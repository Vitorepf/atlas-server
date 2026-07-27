<?php

declare(strict_types=1);

namespace App\Console\Commands\SoftwareCompanyStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeCockpitSurfaceService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalControlReceiptService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalControlsReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingDomainRuntimeCreationHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\DevForgeRuntimeExecutionBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultBridgeService;
use App\Support\YesNo;
use InvalidArgumentException;

trait RuntimeExecutionSection
{
    private function runOwnerQueueConsumptionGate(AreaFocusOwnerQueueConsumptionGateService $service): int
    {
        $releaseFile = (string) ($this->option('release-file') ?? '');
        $outcomeFile = (string) ($this->option('outcome-file') ?? '');
        if ($releaseFile === '') {
            return $this->blockedResult('release_file_required', '--release-file is required for owner-queue-consumption-gate');
        }
        if ($outcomeFile === '') {
            return $this->blockedResult('outcome_file_required', '--outcome-file is required for owner-queue-consumption-gate');
        }

        $releaseRecords = $this->readJsonOrJsonlRecords($releaseFile);
        if ($releaseRecords === null || $releaseRecords === []) {
            return $this->blockedResult('release_file_invalid', $releaseFile);
        }
        $release = $this->selectRecordById($releaseRecords, (string) ($this->option('release-id') ?? ''), 'release_id');
        if ($release === null) {
            return $this->blockedResult('release_id_not_found', (string) $this->option('release-id'));
        }

        $outcome = $this->readJsonFile($outcomeFile);
        if (! is_array($outcome)) {
            return $this->blockedResult('outcome_file_invalid', $outcomeFile);
        }

        $receiptFile = (string) ($this->option('execution-receipt-file') ?? '');
        $receipt = [];
        if ($receiptFile !== '') {
            $receipt = $this->readJsonFile($receiptFile);
            if (! is_array($receipt)) {
                return $this->blockedResult('execution_receipt_file_invalid', $receiptFile);
            }
        }

        $sandboxFile = (string) ($this->option('sandbox-record-file') ?? '');
        $sandboxRecord = [];
        if ($sandboxFile !== '') {
            $sandboxRecords = $this->readJsonOrJsonlRecords($sandboxFile);
            if ($sandboxRecords === null || $sandboxRecords === []) {
                return $this->blockedResult('sandbox_record_file_invalid', $sandboxFile);
            }
            $sandboxRecord = $this->selectRecordById($sandboxRecords, (string) ($this->option('sandbox-id') ?? ''), 'sandbox_id');
            if ($sandboxRecord === null) {
                return $this->blockedResult('sandbox_id_not_found', (string) $this->option('sandbox-id'));
            }
        }

        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'release_report' => $release,
            'outcome_bridge' => $outcome,
            'sandbox_record' => $sandboxRecord,
            'execution_receipt' => $receipt,
            'record_consumption' => (bool) $this->option('record-consumption'),
            'kill_switch' => (bool) $this->option('kill-switch'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-749 owner queue gate', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Target owner', (string) ($p['target_owner'] ?? ''));
            $this->components->twoColumnDetail('Release', (string) ($p['release_id'] ?? ''));
            $this->components->twoColumnDetail('Queue item', (string) ($p['queue_item_id'] ?? ''));
            $this->components->twoColumnDetail('Sandbox', (string) data_get($p, 'sandbox_binding.sandbox_id', ''));
            $this->components->twoColumnDetail('Execution receipt', (string) ($p['execution_receipt_status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_consumption_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === AreaFocusOwnerQueueConsumptionGateService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runOwnerRuntimeExecute(StewardshipOwnerRuntimeExecutionAdapterService $service): int
    {
        $consumptionFile = (string) ($this->option('consumption-file') ?? '');
        $receiptFile = (string) ($this->option('runtime-start-receipt-file') ?? '');
        if ($consumptionFile === '') {
            return $this->blockedResult('consumption_file_required', '--consumption-file is required for owner-runtime-execute');
        }
        if ($receiptFile === '') {
            return $this->blockedResult('runtime_start_receipt_file_required', '--runtime-start-receipt-file is required for owner-runtime-execute');
        }

        $consumptionRecords = $this->readJsonOrJsonlRecords($consumptionFile);
        if ($consumptionRecords === null || $consumptionRecords === []) {
            return $this->blockedResult('consumption_file_invalid', $consumptionFile);
        }
        $consumption = $this->selectRecordById($consumptionRecords, (string) ($this->option('consumption-id') ?? ''), 'consumption_id');
        if ($consumption === null) {
            return $this->blockedResult('consumption_id_not_found', (string) $this->option('consumption-id'));
        }

        $receipt = $this->readJsonFile($receiptFile);
        if (! is_array($receipt)) {
            return $this->blockedResult('runtime_start_receipt_file_invalid', $receiptFile);
        }

        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'consumption_report' => $consumption,
            'runtime_start_receipt' => $receipt,
            'record_execution' => (bool) $this->option('record-execution'),
            'kill_switch' => (bool) $this->option('kill-switch'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-758 owner runtime execute', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Target owner', (string) ($p['target_owner'] ?? ''));
            $this->components->twoColumnDetail('Consumption', (string) ($p['consumption_id'] ?? ''));
            $this->components->twoColumnDetail('Execution', (string) ($p['owner_execution_id'] ?? ''));
            $this->components->twoColumnDetail('Driver', (string) data_get($p, 'runtime_invocation.driver_mode', ''));
            $this->components->twoColumnDetail('Owner result', (string) data_get($p, 'owner_result.result_id', ''));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_execution_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === StewardshipOwnerRuntimeExecutionAdapterService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runOwnerSandboxRuntimeRun(StewardshipOwnerSandboxRuntimeRunnerService $service): int
    {
        $executionFile = (string) ($this->option('execution-file') ?? '');
        $receiptFile = (string) ($this->option('runtime-command-receipt-file') ?? '');
        if ($executionFile === '') {
            return $this->blockedResult('execution_file_required', '--execution-file is required for owner-sandbox-runtime-run');
        }
        if ($receiptFile === '') {
            return $this->blockedResult('runtime_command_receipt_file_required', '--runtime-command-receipt-file is required for owner-sandbox-runtime-run');
        }

        $executionRecords = $this->readJsonOrJsonlRecords($executionFile);
        if ($executionRecords === null || $executionRecords === []) {
            return $this->blockedResult('execution_file_invalid', $executionFile);
        }
        $execution = $this->selectRecordById($executionRecords, (string) ($this->option('owner-execution-id') ?? ''), 'owner_execution_id');
        if ($execution === null) {
            return $this->blockedResult('owner_execution_id_not_found', (string) $this->option('owner-execution-id'));
        }

        $receipt = $this->readJsonFile($receiptFile);
        if (! is_array($receipt)) {
            return $this->blockedResult('runtime_command_receipt_file_invalid', $receiptFile);
        }

        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'execution_adapter_report' => $execution,
            'runtime_command_receipt' => $receipt,
            'execute' => (bool) $this->option('execute-owner-command'),
            'record_run' => (bool) $this->option('record-owner-run'),
            'kill_switch' => (bool) $this->option('kill-switch'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-759 owner sandbox runtime', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Target owner', (string) ($p['target_owner'] ?? ''));
            $this->components->twoColumnDetail('Owner execution', (string) ($p['owner_execution_id'] ?? ''));
            $this->components->twoColumnDetail('Sandbox run', (string) ($p['owner_sandbox_run_id'] ?? ''));
            $this->components->twoColumnDetail('Command', (string) data_get($p, 'command_plan.command_display', ''));
            $this->components->twoColumnDetail('Owner result', (string) data_get($p, 'owner_result.result_id', ''));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_run_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === StewardshipOwnerSandboxRuntimeRunnerService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runOwnerRuntimeResultBridge(StewardshipOwnerRuntimeResultBridgeService $service): int
    {
        $consumptionFile = (string) ($this->option('consumption-file') ?? '');
        $resultFile = (string) ($this->option('result-file') ?? '');
        if ($consumptionFile === '') {
            return $this->blockedResult('consumption_file_required', '--consumption-file is required for owner-runtime-result-bridge');
        }
        if ($resultFile === '') {
            return $this->blockedResult('result_file_required', '--result-file is required for owner-runtime-result-bridge');
        }

        $consumptionRecords = $this->readJsonOrJsonlRecords($consumptionFile);
        if ($consumptionRecords === null || $consumptionRecords === []) {
            return $this->blockedResult('consumption_file_invalid', $consumptionFile);
        }
        $consumption = $this->selectRecordById($consumptionRecords, (string) ($this->option('consumption-id') ?? ''), 'consumption_id');
        if ($consumption === null) {
            return $this->blockedResult('consumption_id_not_found', (string) $this->option('consumption-id'));
        }

        $result = $this->readJsonFile($resultFile);
        if (! is_array($result)) {
            return $this->blockedResult('result_file_invalid', $resultFile);
        }

        $approvalFile = (string) ($this->option('approval-file') ?? '');
        $approval = [];
        if ($approvalFile !== '') {
            $approval = $this->readJsonFile($approvalFile);
            if (! is_array($approval)) {
                return $this->blockedResult('approval_file_invalid', $approvalFile);
            }
        }

        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'consumption_report' => $consumption,
            'owner_result' => $result,
            'irreversible_approval_receipt' => $approval,
            'record_result' => (bool) $this->option('record-result'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-750 owner runtime result bridge', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Target owner', (string) ($p['target_owner'] ?? ''));
            $this->components->twoColumnDetail('Consumption', (string) ($p['consumption_id'] ?? ''));
            $this->components->twoColumnDetail('Result', (string) ($p['owner_result_id'] ?? ''));
            $this->components->twoColumnDetail('Result status', (string) ($p['owner_result_status'] ?? ''));
            $this->components->twoColumnDetail('Evidence items', (string) count((array) ($p['evidence_items'] ?? [])));
            $this->components->twoColumnDetail('Inbox items', (string) count((array) ($p['morning_inbox_items'] ?? [])));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_result_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === StewardshipOwnerRuntimeResultBridgeService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runRuntimeResultBridge(StewardshipRuntimeResultBridgeService $service): int
    {
        $resultFile = (string) ($this->option('result-file') ?? '');
        $useFixture = (bool) $this->option('fixture');

        if ($resultFile === '' && ! $useFixture) {
            return $this->blockedResult('execution_result_required', '--result-file=<owner-result.json> or --fixture is required for runtime-result-bridge');
        }

        if ($useFixture) {
            $result = $this->runtimeResultFixture();
        } else {
            $result = $this->readJsonFile($resultFile);
            if (! is_array($result)) {
                return $this->blockedResult('result_file_invalid', $resultFile);
            }
        }

        $approvalFile = (string) ($this->option('approval-file') ?? '');
        $approval = [];
        if ($approvalFile !== '') {
            $approval = $this->readJsonFile($approvalFile);
            if (! is_array($approval)) {
                return $this->blockedResult('approval_file_invalid', $approvalFile);
            }
        }

        $execution = (string) ($this->option('execution') ?? '');
        if ($execution !== '' && ! isset($result['execution_id'])) {
            $result['execution_id'] = $execution;
        }

        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'owner' => (string) ($this->option('owner') ?? ''),
            'sandbox_id' => (string) ($this->option('sandbox-id') ?? ''),
            'finding_id' => (string) ($this->option('finding-id') ?? ''),
            'spec_id' => (string) ($this->option('spec-id') ?? ''),
            'handoff_id' => (string) ($this->option('handoff-id') ?? ''),
            'actor' => (string) ($this->option('actor') ?? ''),
            'execution_result' => $result,
            'irreversible_approval_receipt' => $approval,
            'emit_inbox' => (bool) $this->option('emit-inbox'),
            'record_evidence' => (bool) $this->option('record-evidence'),
            'record_event' => (bool) $this->option('record-event'),
            'record_cycle' => (bool) $this->option('record-cycle'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-765 runtime result bridge', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Owner', (string) ($p['owner'] ?? ''));
            $this->components->twoColumnDetail('Result', (string) ($p['result_status'] ?? ''));
            $this->components->twoColumnDetail('Bridge', (string) ($p['result_bridge_id'] ?? ''));
            $this->components->twoColumnDetail('Evidence pack', (string) ($p['evidence_pack_id'] ?? '').' ('.(string) ($p['evidence_ledger_status'] ?? '').')');
            $this->components->twoColumnDetail('Inbox item', (string) ($p['inbox_item_id'] ?? data_get($p, 'inbox_item.inbox_status', 'projected')));
            $this->components->twoColumnDetail('Product Mode event', (string) ($p['product_mode_event_id'] ?? '').' ('.(string) ($p['product_mode_event_status'] ?? '').')');
            $this->components->twoColumnDetail('Portfolio signal', (string) ($p['portfolio_signal_id'] ?? ''));
            $this->components->twoColumnDetail('Cycle recorded', (string) ($p['cycle_storage_status'] ?? 'projected'));
            if (($p['status'] ?? '') !== StewardshipRuntimeResultBridgeService::STATUS_BLOCKED) {
                $this->line('  next: '.(string) ($p['operator_next_step'] ?? ''));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
        });

        return ($payload['status'] ?? '') === StewardshipRuntimeResultBridgeService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * Built-in canonical execution_result fixture for smoke/demo and the
     * `--fixture` flag. Represents a completed, branch-isolated Atlas Dev run.
     *
     * @return array<string,mixed>
     */
    private function runDevForgeExecute(DevForgeRuntimeExecutionBridgeService $service): int
    {
        $owner = (string) ($this->option('owner') ?? '');
        if ($owner === '') {
            return $this->blockedResult('owner_required', '--owner=atlas_dev|forge is required for dev-forge-execute');
        }

        $sandboxId = (string) ($this->option('sandbox') ?? $this->option('sandbox-id') ?? '');
        $sandbox = null;
        $descriptorFile = (string) ($this->option('sandbox-descriptor-file') ?? '');
        if ($descriptorFile !== '') {
            $sandbox = $this->readJsonFile($descriptorFile);
            if (! is_array($sandbox)) {
                return $this->blockedResult('sandbox_descriptor_file_invalid', $descriptorFile);
            }
        } else {
            $recordFile = (string) ($this->option('sandbox-record-file') ?? '');
            if ($recordFile !== '') {
                $records = $this->readJsonOrJsonlRecords($recordFile);
                if ($records === null || $records === []) {
                    return $this->blockedResult('sandbox_record_file_invalid', $recordFile);
                }
                $sandbox = $this->selectRecordById($records, $sandboxId, 'sandbox_id');
                if ($sandbox === null) {
                    return $this->blockedResult('sandbox_id_not_found', $sandboxId);
                }
            } elseif ($sandboxId !== '') {
                // Minimal id-only descriptor; the gate blocks honestly on missing worktree/isolation.
                $sandbox = ['sandbox_id' => $sandboxId];
            }
        }

        $payload = $service->execute([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'owner' => $owner,
            'mode' => (string) ($this->option('mode') ?? 'dry-run'),
            'handoff_id' => (string) ($this->option('handoff') ?? $this->option('handoff-id') ?? ''),
            'finding_id' => (string) ($this->option('finding-id') ?? ''),
            'spec_id' => (string) ($this->option('spec-id') ?? ''),
            'allowed_files' => array_values(array_filter((array) $this->option('allowed-file'), 'is_string')),
            'test_commands' => array_values(array_filter((array) $this->option('test-command'), 'is_string')),
            'sandbox' => $sandbox,
            'run_local_deterministic_task' => (bool) $this->option('run-local-task'),
            'record_result' => (bool) $this->option('record-bridge-result'),
            'kill_switch' => (bool) $this->option('kill-switch'),
            'area_kill_switch' => (bool) $this->option('area-kill-switch'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-767 dev-forge-execute', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Owner', (string) ($p['owner'] ?? ''));
            $this->components->twoColumnDetail('Mode', (string) ($p['mode'] ?? ''));
            $this->components->twoColumnDetail('Execution', (string) ($p['execution_id'] ?? ''));
            $this->components->twoColumnDetail('Sandbox', (string) data_get($p, 'sandbox.sandbox_id', ''));
            $this->components->twoColumnDetail('Provider bridge', ((bool) data_get($p, 'provider_bridge.provider_bridge_missing', false)) ? 'missing' : 'bound');
            $this->components->twoColumnDetail('Next state', (string) ($p['next_state'] ?? ''));
            $this->components->twoColumnDetail('Recorded', (string) ($p['execution_storage_status'] ?? 'projected'));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === DevForgeRuntimeExecutionBridgeService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runProductModeCockpit(ProductModeCockpitSurfaceService $service, ProductModeOperationalControlReceiptService $controlReceipts): int
    {
        $input = [
            'area_id' => (string) $this->option('area'),
            'pack_id' => (string) $this->option('pack-id'),
            'proposal_id' => (string) $this->option('proposal-id'),
        ] + $this->productModeControlInput();
        if ((bool) $this->option('use-recorded-controls')) {
            $input = $controlReceipts->effectiveControls((string) $this->option('area'), (string) $this->option('portfolio'), $input);
        }

        $payload = $service->project((string) $this->option('portfolio'), $input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-739 Product Mode Cockpit', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Review queue', (string) data_get($p, 'counters.review_queue_items', 0));
            $this->components->twoColumnDetail('Executive pending', (string) data_get($p, 'counters.executive_pending_review', 0));
            $this->components->twoColumnDetail('New area blocked', (string) data_get($p, 'counters.new_area_blocked_review', 0));
            $this->components->twoColumnDetail('Outcome evidence', (string) data_get($p, 'counters.outcome_evidence_items', 0));
            $this->components->twoColumnDetail('Domain handoffs', (string) data_get($p, 'counters.ready_domain_handoffs', 0).'/'.(string) data_get($p, 'counters.domain_handoff_packets', 0).' ready');
            $this->components->twoColumnDetail('Product controls', (string) data_get($p, 'product_mode_operational_controls.status', 'unknown'));
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === ProductModeCockpitSurfaceService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runProductModeControls(ProductModeOperationalControlsReadModelService $service, ProductModeOperationalControlReceiptService $controlReceipts): int
    {
        $input = $this->productModeControlInput();
        if ((bool) $this->option('use-recorded-controls')) {
            $input = $controlReceipts->effectiveControls((string) $this->option('area'), (string) $this->option('portfolio'), $input);
        }

        $payload = $service->project((string) $this->option('area'), (string) $this->option('portfolio'), $input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-754 Product Mode controls', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Repo', (string) data_get($p, 'repo_onboarding.repository', ''));
            $this->components->twoColumnDetail('Repo authorized', YesNo::format((bool) data_get($p, 'repo_onboarding.is_authorized', false)));
            $this->components->twoColumnDetail('Autonomy tier', (string) data_get($p, 'autonomy_tiers.current_tier', 0).' / max '.(string) data_get($p, 'autonomy_tiers.max_allowed_tier', 0));
            $this->components->twoColumnDetail('Kill switch', ((bool) data_get($p, 'safety_controls.kill_switch_active', false)) ? 'active' : 'clear');
            $this->components->twoColumnDetail('Evidence', (string) data_get($p, 'evidence_inspector.inspector_status', 'unknown'));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === ProductModeOperationalControlsReadModelService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runProductModeControlReceipt(ProductModeOperationalControlReceiptService $service): int
    {
        try {
            $payload = $service->record($this->productModeControlInput() + [
                'area_id' => (string) $this->option('area'),
                'portfolio_id' => (string) $this->option('portfolio'),
                'control_type' => (string) $this->option('control-type'),
                'operator_actor' => (string) $this->option('actor'),
                'decision' => (string) ($this->option('decision') ?: 'accept'),
                'risk' => (string) $this->option('risk'),
                'rationale' => (string) $this->option('rationale'),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->blockedResult('product_mode_control_receipt_blocked', $e->getMessage());
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-755 Product Mode control receipt', (string) ($p['decision_id'] ?? ''));
            $this->components->twoColumnDetail('Control', (string) data_get($p, 'target_payload.control_type', ''));
            $this->components->twoColumnDetail('Repo', (string) data_get($p, 'target_payload.repo', ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['decision'] ?? ''));
            $this->components->twoColumnDetail('Executed', YesNo::format((bool) ($p['executed'] ?? false)));
        });

        return self::SUCCESS;
    }

    private function runProductModeControlReceiptList(ProductModeOperationalControlReceiptService $service): int
    {
        $payload = $service->listReceipts((string) $this->option('area'), (string) $this->option('portfolio'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-755 Product Mode control receipts', (string) ($p['receipt_count'] ?? 0));
            foreach ((array) ($p['receipts'] ?? []) as $receipt) {
                $this->line(sprintf(
                    '  %s · %s · %s · %s',
                    (string) ($receipt['decision_id'] ?? ''),
                    (string) ($receipt['control_type'] ?? ''),
                    (string) ($receipt['decision'] ?? ''),
                    (string) ($receipt['repo'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runProductModeControlReceiptReplay(ProductModeOperationalControlReceiptService $service): int
    {
        $decisionId = trim((string) $this->option('decision-id'));
        if ($decisionId === '') {
            return $this->blockedResult('decision_id_required', '--decision-id is required for product-mode-control-replay');
        }

        $payload = $service->replay($decisionId);
        if ($payload === null) {
            return $this->blockedResult('product_mode_control_receipt_not_found', $decisionId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-755 replay', (string) ($p['decision_id'] ?? ''));
            $this->components->twoColumnDetail('Control', (string) data_get($p, 'target_payload.control_type', ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['decision'] ?? ''));
        });

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function runOutcomeEvidence(StewardshipOutcomeEvidenceBridgeService $service): int
    {
        $releaseFile = (string) ($this->option('release-file') ?? '');
        $releaseReports = $releaseFile !== '' ? $this->readJsonOrJsonlRecords($releaseFile) : null;
        if ($releaseFile !== '' && $releaseReports === null) {
            return $this->blockedResult('release_file_invalid', $releaseFile);
        }

        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'proposal_id' => (string) $this->option('proposal-id'),
            'actor' => (string) $this->option('actor'),
            'record_evidence' => (bool) $this->option('record-evidence'),
            'emit_inbox' => (bool) $this->option('emit-inbox'),
            'release_reports' => $releaseReports ?? [],
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-740 outcome bridge', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Decisions', (string) ($p['decision_count'] ?? 0));
            $this->components->twoColumnDetail('AP-747 releases', (string) data_get($p, 'release_outcome_summary.release_count', 0));
            $this->components->twoColumnDetail('Evidence items', (string) ($p['evidence_item_count'] ?? 0));
            $this->components->twoColumnDetail('Morning Inbox items', (string) ($p['morning_inbox_item_count'] ?? 0));
            $this->components->twoColumnDetail('Portfolio feed areas', (string) count((array) data_get($p, 'portfolio_feed.areas', [])));
            $this->components->twoColumnDetail('Ledger writes', ((bool) ($p['record_evidence_requested'] ?? false)) ? 'requested' : 'projection-only');
            $this->components->twoColumnDetail('Inbox emits', ((bool) ($p['emit_inbox_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ($p['morning_inbox_items'] ?? [] as $item) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($item['kind'] ?? ''),
                    (string) ($item['target_id'] ?? $item['proposal_id'] ?? ''),
                    (string) ($item['recommended_action'] ?? ''),
                ));
            }
        });

        return ($payload['status'] ?? '') === StewardshipOutcomeEvidenceBridgeService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runDomainRuntimeCreationHandoff(SelfExpandingDomainRuntimeCreationHandoffService $service): int
    {
        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'proposal_id' => (string) $this->option('proposal-id'),
            'record_handoff' => (bool) $this->option('record-handoff'),
            'require_recorded_evidence' => ! (bool) $this->option('allow-projected-evidence'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-741 creation handoff', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Ready packets', (string) ($p['ready_handoff_count'] ?? 0));
            $this->components->twoColumnDetail('Blocked packets', (string) ($p['blocked_handoff_count'] ?? 0));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_handoff_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ($p['handoff_packets'] ?? [] as $packet) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($packet['handoff_packet_id'] ?? ''),
                    (string) ($packet['candidate_area'] ?? ''),
                    (string) ($packet['handoff_status'] ?? ''),
                ));
                foreach ((array) ($packet['blockers'] ?? []) as $blocker) {
                    $this->warn('    blocker: '.(string) $blocker);
                }
            }
        });

        return ($payload['status'] ?? '') === SelfExpandingDomainRuntimeCreationHandoffService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }
}

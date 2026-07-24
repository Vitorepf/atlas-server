<?php

declare(strict_types=1);

namespace App\Console\Commands\SoftwareCompanyStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtlasAreaFocusLoopReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipPromotionReadinessService;
use App\Support\YesNo;

trait AreaFocusSection
{
    private function runAreaFocus(AtlasAreaFocusLoopReadModelService $readModel): int
    {
        $payload = $readModel->project(['area_id' => (string) $this->option('area')]);

        $this->emit($payload, function (array $p): void {
            $stack = is_array($p['stewardship_stack'] ?? null) ? $p['stewardship_stack'] : [];
            $this->components->twoColumnDetail('Stewardship Stack', (string) ($stack['level'] ?? 'Area Focus Loop').' (read-only)');
            $this->components->twoColumnDetail('Schema', (string) ($p['schema_version'] ?? ''));
            $this->components->twoColumnDetail('Status', (string) ($p['status'] ?? 'unknown'));

            $contract = is_array($p['area_contract'] ?? null) ? $p['area_contract'] : null;
            if ($contract !== null) {
                $this->components->twoColumnDetail('Area', (string) ($contract['area_name'] ?? '').' ('.(string) ($contract['area_id'] ?? '').')');
            }

            $readiness = is_array($p['readiness'] ?? null) ? $p['readiness'] : [];
            $checks = is_array($readiness['checks'] ?? null) ? $readiness['checks'] : [];
            $this->components->twoColumnDetail(
                'Owner docs present',
                (string) ($checks['owner_docs_present'] ?? 0).'/'.(string) ($checks['owner_docs_total'] ?? 0),
            );
            $this->components->twoColumnDetail('Finding seeds', (string) ($p['finding_seed_count'] ?? 0));

            foreach ($p['finding_seeds'] ?? [] as $seed) {
                $this->line(sprintf(
                    '  [%s] %s · %s -> %s',
                    (string) ($seed['severity'] ?? '?'),
                    (string) ($seed['kind'] ?? ''),
                    (string) ($seed['summary'] ?? ''),
                    (string) ($seed['recommended_owner'] ?? ''),
                ));
            }
            foreach ($p['blockers'] ?? [] as $blocker) {
                $this->warn(sprintf(
                    '  blocker: %s · %s',
                    (string) ($blocker['reason'] ?? '?'),
                    (string) ($blocker['detail'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runAreaFocusDeepScan(AreaFocusDeepFindingEngineService $service): int
    {
        $maxFindings = $this->option('max-findings');
        $input = [
            'area_id' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'record' => (bool) $this->option('record'),
        ];
        if ($maxFindings !== null && $maxFindings !== '' && is_numeric($maxFindings)) {
            $input['max_findings'] = (int) $maxFindings;
        }
        if ((bool) $this->option('scan-canonical-doc-backlog') === true) {
            $input['scan_canonical_doc_backlog'] = true;
        }

        $payload = $service->scan($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-748 Area Focus deep scan', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Focus', (string) ($p['focus'] ?? '').' ('.(string) ($p['focus_label'] ?? '').')');
            $this->components->twoColumnDetail('Scan', (string) ($p['scan_id'] ?? ''));
            $this->components->twoColumnDetail('Mode', (string) ($p['mode'] ?? ''));
            $this->components->twoColumnDetail('Findings', (string) ($p['finding_count'] ?? 0).(($p['capped'] ?? false) ? ' (capped)' : ''));
            $this->components->twoColumnDetail('In focus', (string) data_get($p, 'focus_summary.in_focus', 0));
            $this->components->twoColumnDetail('Recorded', (string) (data_get($p, 'record.recorded', false) ? 'yes' : (($p['mode'] ?? '') === 'record' ? 'idempotent/skipped' : 'projection-only')));
            foreach (array_slice((array) ($p['findings'] ?? []), 0, 10) as $finding) {
                $this->line(sprintf(
                    '  [%s] %s · %s -> %s · %s',
                    (string) ($finding['severity'] ?? '?'),
                    (string) ($finding['kind'] ?? ''),
                    (string) ($finding['title'] ?? ''),
                    (string) ($finding['owner_candidate'] ?? ''),
                    ((bool) ($finding['in_focus'] ?? false)) ? 'in-focus' : 'out',
                ));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) ($blocker['reason'] ?? '?').' · '.(string) ($blocker['detail'] ?? ''));
            }
        });

        return ($payload['status'] ?? '') === AreaFocusDeepFindingEngineService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runAreaFocusDeepScanList(AreaFocusDeepFindingEngineService $service): int
    {
        $payload = $service->listScans((string) $this->option('area'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-748 deep scans', (string) ($p['scan_count'] ?? 0));
            foreach ((array) ($p['scans'] ?? []) as $scan) {
                $this->line(sprintf(
                    '  %s · %s · %s · %d findings · %s',
                    (string) ($scan['scan_id'] ?? ''),
                    (string) ($scan['focus'] ?? ''),
                    (string) ($scan['status'] ?? ''),
                    (int) ($scan['finding_count'] ?? 0),
                    (string) ($scan['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runAreaFocusDeepScanReplay(AreaFocusDeepFindingEngineService $service): int
    {
        $scanId = trim((string) $this->option('scan-id'));
        if ($scanId === '') {
            return $this->blockedResult('scan_id_required', '--scan-id is required for area-focus-deep-scan-replay');
        }

        $payload = $service->replay($scanId);
        if ($payload === null) {
            return $this->blockedResult('scan_not_found', $scanId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-748 deep scan replay', (string) ($p['scan_id'] ?? ''));
            $this->components->twoColumnDetail('Focus', (string) ($p['focus'] ?? ''));
            $this->components->twoColumnDetail('Status', (string) ($p['status'] ?? ''));
            $this->components->twoColumnDetail('Findings', (string) ($p['finding_count'] ?? 0));
        });

        return self::SUCCESS;
    }

    private function runAreaFocusDevForgeRelease(AreaFocusDevForgeReleaseService $service): int
    {
        $preflightFile = (string) ($this->option('preflight-file') ?? '');
        $receiptFile = (string) ($this->option('release-receipt-file') ?? '');
        if ($preflightFile === '') {
            return $this->blockedResult('preflight_file_required', '--preflight-file is required for area-focus-dev-forge-release');
        }
        if ($receiptFile === '') {
            return $this->blockedResult('release_receipt_file_required', '--release-receipt-file is required for area-focus-dev-forge-release');
        }

        $preflight = $this->readJsonFile($preflightFile);
        if (! is_array($preflight)) {
            return $this->blockedResult('preflight_file_invalid', $preflightFile);
        }
        $receipt = $this->readJsonFile($receiptFile);
        if (! is_array($receipt)) {
            return $this->blockedResult('release_receipt_file_invalid', $receiptFile);
        }

        $payload = $service->release([
            'area_id' => (string) $this->option('area'),
            'preflight_report' => $preflight,
            'release_receipt' => $receipt,
            'record_release' => (bool) $this->option('record-release'),
            'workspace' => (string) $this->option('workspace'),
            'kill_switch' => (bool) $this->option('kill-switch'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-747 Dev/Forge release', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Target owner', (string) ($p['target_owner'] ?? ''));
            $this->components->twoColumnDetail('Release', (string) ($p['release_id'] ?? ''));
            $this->components->twoColumnDetail('Queue item', (string) data_get($p, 'queue_item.queue_item_id', ''));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_release_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === AreaFocusDevForgeReleaseService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runAreaFocusBranchSandboxMaterialize(AreaFocusBranchSandboxMaterializerService $service): int
    {
        $preflightFile = (string) ($this->option('preflight-file') ?? '');
        $receiptFile = (string) ($this->option('sandbox-receipt-file') ?? '');
        if ($preflightFile === '') {
            return $this->blockedResult('preflight_file_required', '--preflight-file is required for area-focus-branch-sandbox-materialize');
        }
        if ($receiptFile === '') {
            return $this->blockedResult('sandbox_receipt_file_required', '--sandbox-receipt-file is required for area-focus-branch-sandbox-materialize');
        }

        $preflight = $this->readJsonFile($preflightFile);
        if (! is_array($preflight)) {
            return $this->blockedResult('preflight_file_invalid', $preflightFile);
        }
        $receipt = $this->readJsonFile($receiptFile);
        if (! is_array($receipt)) {
            return $this->blockedResult('sandbox_receipt_file_invalid', $receiptFile);
        }

        $payload = $service->materialize([
            'area_id' => (string) $this->option('area'),
            'preflight_report' => $preflight,
            'sandbox_receipt' => $receipt,
            'materialize_sandbox' => (bool) $this->option('materialize-sandbox'),
            'record_sandbox' => (bool) $this->option('record-sandbox'),
            'repo_root' => (string) ($this->option('repo-root') ?: base_path()),
            'base_ref' => (string) $this->option('base-ref'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-756 branch sandbox', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Sandbox', (string) ($p['sandbox_id'] ?? ''));
            $this->components->twoColumnDetail('Branch', (string) data_get($p, 'materialization.branch_name', ''));
            $this->components->twoColumnDetail('Worktree', (string) data_get($p, 'materialization.worktree_path', ''));
            $this->components->twoColumnDetail('Recorded', (string) ($p['sandbox_storage_status'] ?? 'projected'));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === AreaFocusBranchSandboxMaterializerService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runAreaFocusBranchSandboxList(AreaFocusBranchSandboxMaterializerService $service): int
    {
        $payload = $service->listSandboxes((string) $this->option('area'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-756 sandboxes', (string) ($p['sandbox_count'] ?? 0));
            foreach ((array) ($p['sandboxes'] ?? []) as $sandbox) {
                $this->line(sprintf(
                    '  %s · %s · %s · %s',
                    (string) ($sandbox['sandbox_id'] ?? ''),
                    (string) ($sandbox['status'] ?? ''),
                    (string) data_get($sandbox, 'materialization.branch_name', ''),
                    (string) ($sandbox['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runAreaFocusBranchSandboxReplay(AreaFocusBranchSandboxMaterializerService $service): int
    {
        $sandboxId = trim((string) $this->option('sandbox-id'));
        if ($sandboxId === '') {
            return $this->blockedResult('sandbox_id_required', '--sandbox-id is required for area-focus-branch-sandbox-replay');
        }

        $payload = $service->replay($sandboxId, (string) $this->option('area'));
        if ($payload === null) {
            return $this->blockedResult('sandbox_not_found', $sandboxId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-756 replay', (string) ($p['sandbox_id'] ?? ''));
            $this->components->twoColumnDetail('Status', (string) ($p['status'] ?? ''));
            $this->components->twoColumnDetail('Branch', (string) data_get($p, 'materialization.branch_name', ''));
            $this->components->twoColumnDetail('Worktree', (string) data_get($p, 'materialization.worktree_path', ''));
        });

        return self::SUCCESS;
    }

    private function runAreaFocusBranchSandboxCleanup(AreaFocusBranchSandboxMaterializerService $service): int
    {
        $sandboxId = trim((string) $this->option('sandbox-id'));
        if ($sandboxId === '') {
            return $this->blockedResult('sandbox_id_required', '--sandbox-id is required for area-focus-branch-sandbox-cleanup');
        }

        $payload = $service->cleanupSandbox([
            'sandbox_id' => $sandboxId,
            'area_id' => (string) $this->option('area'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'remove_sandbox' => (bool) $this->option('remove-sandbox'),
            'allow_dirty_removal' => (bool) $this->option('allow-dirty-removal'),
            'delete_branch' => (bool) $this->option('delete-branch'),
            'allow_unmerged_branch_delete' => (bool) $this->option('allow-unmerged-branch-delete'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-756 cleanup', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Sandbox', (string) ($p['sandbox_id'] ?? ''));
            $this->components->twoColumnDetail('Mode', (string) ($p['mode'] ?? ''));
            $this->components->twoColumnDetail('Worktree removed', data_getYesNo::format($p, 'actions.worktree_removed'));
            $this->components->twoColumnDetail('Branch deleted', data_getYesNo::format($p, 'actions.branch_deleted'));
            $this->components->twoColumnDetail('Recorded', (string) ($p['cleanup_storage_status'] ?? 'projected'));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === AreaFocusBranchSandboxMaterializerService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runAreaStewardshipReadiness(AreaStewardshipPromotionReadinessService $service): int
    {
        $payload = $service->assess(['area_id' => (string) $this->option('area')]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('Area Stewardship readiness', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Target', (string) ($p['target_id'] ?? ''));
            $this->components->twoColumnDetail('Operator acceptance', (string) data_get($p, 'operator_acceptance.status', 'unknown'));
            foreach ($p['blockers'] ?? [] as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
        });

        return ($payload['status'] ?? '') === AreaStewardshipPromotionReadinessService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runAreaStewardshipActiveHandoff(AreaStewardshipActiveHandoffService $service): int
    {
        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'record_active_handoff' => (bool) $this->option('record-active-handoff'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-743 Area Stewardship active handoff', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Readiness', (string) ($p['readiness_status'] ?? ''));
            $this->components->twoColumnDetail('Packets', (string) ($p['active_handoff_count'] ?? 0));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_active_handoff_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === AreaStewardshipActiveHandoffService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runAreaStewardshipActiveOperate(AreaStewardshipActiveOperatingService $service): int
    {
        $operatorReceipts = $this->operatorReceiptsFromOption();
        if ($operatorReceipts === null) {
            return $this->blockedResult('operator_receipts_file_invalid', '--operator-receipts-file must be a readable JSON object, JSON array, or JSONL file.');
        }

        $payload = $service->operate([
            'area_id' => (string) $this->option('area'),
            'record_active_operation' => (bool) $this->option('record-active-operation'),
            'operator_receipts' => $operatorReceipts,
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-744 Area Stewardship active operation', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Operation', (string) ($p['operation_id'] ?? ''));
            $this->components->twoColumnDetail('Handoff', (string) ($p['active_handoff_status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Cycle', (string) ($p['operational_cycle_id'] ?? ''));
            $this->components->twoColumnDetail('Findings', (string) data_get($p, 'counts.findings', 0));
            $this->components->twoColumnDetail('Work orders', (string) data_get($p, 'counts.work_orders', 0));
            $this->components->twoColumnDetail('Spec drafts', (string) data_get($p, 'counts.spec_drafts', 0));
            $this->components->twoColumnDetail('Ready handoffs', (string) data_get($p, 'counts.ready_branch_handoffs', 0));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_active_operation_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === AreaStewardshipActiveOperatingService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

}

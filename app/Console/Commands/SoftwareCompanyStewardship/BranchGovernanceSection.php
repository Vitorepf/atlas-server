<?php

declare(strict_types=1);

namespace App\Console\Commands\SoftwareCompanyStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FirstFullCycleOrchestratorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchLifecycleRegistryService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchSafetyAuditService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchStressCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchSystemCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipMergeQueueService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipRepoMergeLeaseService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipNativeObraRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipCompletionAuditService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipLiveCycleCertificationService;
use Illuminate\Console\Command;

trait BranchGovernanceSection
{
    private function runFirstFullCycle(FirstFullCycleOrchestratorService $service): int
    {
        $input = [
            'area_id' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'mode' => (string) $this->option('mode'),
            'owner' => (string) ($this->option('owner') ?? ''),
            'actor' => (string) ($this->option('actor') ?? ''),
            'record' => (bool) $this->option('record'),
            'materialize_sandbox' => (bool) $this->option('materialize-sandbox'),
            'run_local_task' => (bool) $this->option('run-local-task'),
            'run_real_atlas_dev' => (bool) $this->option('run-real-atlas-dev'),
            'real_atlas_dev_intent' => (string) ($this->option('real-atlas-dev-intent') ?? ''),
            'allowed_files' => array_values(array_filter((array) $this->option('allowed-file'), 'is_string')),
            'test_commands' => array_values(array_filter((array) $this->option('test-command'), 'is_string')),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'base_ref' => (string) ($this->option('base-ref') ?: 'main'),
            'branch_ref' => (string) ($this->option('branch-ref') ?: ''),
            'auto_merge' => (bool) $this->option('auto-merge'),
            'execute_merge' => (bool) $this->option('execute-merge'),
            'auto_merge_class' => (string) ($this->option('auto-merge-class') ?? ''),
            'allow_code_auto_merge' => (bool) $this->option('allow-code-auto-merge'),
            'max_auto_merge_files' => (int) ($this->option('max-auto-merge-files') ?: 5),
            'run_validation' => (bool) $this->option('run-validation'),
            'record_merge_governance' => (bool) $this->option('record-governance'),
            'emit_inbox' => (bool) $this->option('emit-inbox'),
        ];
        $maxFindings = $this->option('max-findings');
        if ($maxFindings !== null && $maxFindings !== '' && is_numeric($maxFindings)) {
            $input['max_findings'] = (int) $maxFindings;
        }
        $preflight = $this->readJsonFile((string) ($this->option('preflight-file') ?? ''));
        if (is_array($preflight)) {
            $input['preflight_report'] = $preflight;
        }
        $sandboxReceipt = $this->readJsonFile((string) ($this->option('sandbox-receipt-file') ?? ''));
        if (is_array($sandboxReceipt)) {
            $input['sandbox_receipt'] = $sandboxReceipt;
        }
        $sandboxDescriptor = $this->readJsonFile((string) ($this->option('sandbox-descriptor-file') ?? ''));
        if (is_array($sandboxDescriptor)) {
            $input['sandbox_descriptor'] = $sandboxDescriptor;
        }

        $payload = $service->run($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-768 first full cycle', (string) ($p['final_status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area / Focus', (string) ($p['area_id'] ?? '').' / '.(string) ($p['focus'] ?? ''));
            $this->components->twoColumnDetail('Mode', (string) ($p['mode'] ?? ''));
            $this->components->twoColumnDetail('Cycle', (string) ($p['cycle_id'] ?? ''));
            $this->components->twoColumnDetail('Scan', (string) ($p['scan_id'] ?? ''));
            $this->components->twoColumnDetail('Recorded', (string) ($p['cycle_storage_status'] ?? 'projected'));
            $finding = is_array($p['selected_finding'] ?? null) ? $p['selected_finding'] : [];
            $this->components->twoColumnDetail('Selected finding', (string) ($finding['title'] ?? '(none)'));
            foreach ((array) ($p['stage_order'] ?? []) as $key) {
                $stage = data_get($p, 'stages.'.$key, []);
                $this->line(sprintf('  [%s] %s · %s — %s',
                    (string) data_get($stage, 'status', '?'),
                    (string) data_get($stage, 'ap_contract', ''),
                    (string) $key,
                    (string) data_get($stage, 'note', ''),
                ));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_operator_action'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['final_status'] ?? '') === FirstFullCycleOrchestratorService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runFirstFullCycleList(FirstFullCycleOrchestratorService $service): int
    {
        $payload = $service->listCycles((string) $this->option('area'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-768 cycles', (string) ($p['cycle_count'] ?? 0));
            foreach ((array) ($p['cycles'] ?? []) as $cycle) {
                $this->line(sprintf('  %s · %s · %s · %s · %s',
                    (string) ($cycle['cycle_id'] ?? ''),
                    (string) ($cycle['focus'] ?? ''),
                    (string) ($cycle['mode'] ?? ''),
                    (string) ($cycle['final_status'] ?? ''),
                    (string) ($cycle['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runFirstFullCycleReplay(FirstFullCycleOrchestratorService $service): int
    {
        $cycleId = trim((string) $this->option('cycle-id'));
        if ($cycleId === '') {
            return $this->blockedResult('cycle_id_required', '--cycle-id is required for first-full-cycle-replay');
        }

        $payload = $service->replay($cycleId, (string) $this->option('area'));
        if ($payload === null) {
            return $this->blockedResult('cycle_not_found', $cycleId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-768 cycle replay', (string) ($p['cycle_id'] ?? ''));
            $this->components->twoColumnDetail('Final status', (string) ($p['final_status'] ?? ''));
            $this->components->twoColumnDetail('Focus', (string) ($p['focus'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function runPriorityRank(StewardshipPriorityEngineService $service): int
    {
        $input = [
            'area_id' => (string) $this->option('area'),
        ];
        $filePayload = $this->readJsonFile((string) ($this->option('priority-file') ?? ''));
        if (is_array($filePayload)) {
            $input += $filePayload;
            if (array_is_list($filePayload)) {
                $input['candidates'] = $filePayload;
            }
        } else {
            $scan = $this->readJsonFile((string) ($this->option('scan-id') ?? ''));
            if (is_array($scan)) {
                $input['deep_scan_report'] = $scan;
            }
        }

        $payload = $service->rank($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-771 priority engine', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Candidates', (string) ($p['candidate_count'] ?? 0));
            $top = is_array($p['top_candidate'] ?? null) ? $p['top_candidate'] : [];
            $this->components->twoColumnDetail('Top candidate', (string) ($top['title'] ?? '(none)'));
            $this->components->twoColumnDetail('Top score', (string) ($top['priority_score'] ?? ''));
            foreach (array_slice((array) ($p['ranked_candidates'] ?? []), 0, 10) as $item) {
                $this->line(sprintf(
                    '  #%d %s · %s · %s',
                    (int) ($item['rank'] ?? 0),
                    (string) ($item['priority_score'] ?? ''),
                    (string) ($item['priority_band'] ?? ''),
                    (string) ($item['title'] ?? ''),
                ));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
        });

        return ($payload['status'] ?? '') === StewardshipPriorityEngineService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runBranchSystemCertification(StewardshipBranchSystemCertificationService $service): int
    {
        $payload = $service->certify([
            'area_id' => (string) $this->option('area'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'include_branch_stress' => (bool) $this->option('include-branch-stress'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-776 branch system certification', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('AP-779 stress extension', (string) data_get($p, 'optional_readiness_extensions.branch_stress.status', 'not_run'));
            $this->components->twoColumnDetail('Components', (string) count((array) ($p['components'] ?? [])));
            $this->components->twoColumnDetail('Command actions', (string) count((array) data_get($p, 'command_actions.coverage', [])));
            foreach ((array) ($p['components'] ?? []) as $component) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($component['ap_contract'] ?? ''),
                    (string) ($component['status'] ?? ''),
                    (string) ($component['component_id'] ?? ''),
                ));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
        });

        return ($payload['status'] ?? '') === StewardshipBranchSystemCertificationService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runBranchStressCertification(StewardshipBranchStressCertificationService $service): int
    {
        $payload = $service->certify([
            'area_id' => (string) $this->option('area'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'preserve_tmp' => (bool) $this->option('preserve-stress-tmp'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-779 branch stress certification', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Scenarios', (string) ($p['passed_scenario_count'] ?? 0).'/'.(string) ($p['scenario_count'] ?? 0));
            $this->components->twoColumnDetail('Branch system', (string) ($p['branch_system_certification_status'] ?? 'unknown'));
            foreach ((array) ($p['scenarios'] ?? []) as $scenario) {
                $this->line(sprintf(
                    '  %s · %s',
                    (string) ($scenario['status'] ?? ''),
                    (string) ($scenario['id'] ?? ''),
                ));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === StewardshipBranchStressCertificationService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runBranchSafetyAudit(StewardshipBranchSafetyAuditService $service): int
    {
        $payload = $service->audit([
            'area_id' => (string) $this->option('area'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'base_ref' => (string) ($this->option('base-ref') ?: 'main'),
            'branch_refs' => (string) ($this->option('branch-ref') ?: ''),
            'branch_prefix' => (string) ($this->option('branch-prefix') ?: 'atlas/area-focus/'),
            'max_auto_merge_files' => (int) ($this->option('max-auto-merge-files') ?: 5),
            'record_audit' => (bool) $this->option('record-branch-audit'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-773 branch safety audit', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Audit', (string) ($p['audit_id'] ?? ''));
            $this->components->twoColumnDetail('Branches', (string) ($p['branch_count'] ?? 0));
            $this->components->twoColumnDetail('Queue ready', (string) data_get($p, 'summary.queue_ready', 0));
            $this->components->twoColumnDetail('Blocked', (string) data_get($p, 'summary.blocked', 0));
            foreach (array_slice((array) ($p['branches'] ?? []), 0, 12) as $item) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($item['safety_state'] ?? ''),
                    (string) ($item['risk_class'] ?? ''),
                    (string) ($item['branch_ref'] ?? ''),
                ));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
        });

        return ($payload['status'] ?? '') === StewardshipBranchSafetyAuditService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runBranchSafetyAuditRecords(StewardshipBranchSafetyAuditService $service): int
    {
        $payload = $service->listRecords((string) $this->option('area'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-773 branch safety audit records', (string) ($p['record_count'] ?? 0));
            foreach ((array) ($p['records'] ?? []) as $record) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($record['audit_id'] ?? ''),
                    (string) ($record['status'] ?? ''),
                    (string) ($record['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runRepoMergeLeaseAcquire(StewardshipRepoMergeLeaseService $service): int
    {
        $payload = $service->acquire([
            'area_id' => (string) $this->option('area'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'base_ref' => (string) ($this->option('base-ref') ?: 'main'),
            'owner' => (string) ($this->option('lease-owner') ?: 'operator_cli'),
            'ttl_seconds' => (int) ($this->option('lease-ttl') ?: 1800),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-775 repo merge lease', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Lease', (string) ($p['lease_id'] ?? ''));
            $this->components->twoColumnDetail('Owner', (string) ($p['owner'] ?? ''));
            $this->components->twoColumnDetail('Expires', (string) data_get($p, 'lease.expires_at', ''));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
        });

        return ($payload['status'] ?? '') === StewardshipRepoMergeLeaseService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runRepoMergeLeaseRelease(StewardshipRepoMergeLeaseService $service): int
    {
        $payload = $service->release([
            'area_id' => (string) $this->option('area'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'base_ref' => (string) ($this->option('base-ref') ?: 'main'),
            'owner' => (string) ($this->option('lease-owner') ?: 'operator_cli'),
            'release_reason' => (string) ($this->option('release-reason') ?: 'operator_release'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-775 repo merge lease release', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Lease', (string) ($p['lease_id'] ?? ''));
            $this->components->twoColumnDetail('Owner', (string) ($p['owner'] ?? ''));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
        });

        return ($payload['status'] ?? '') === StewardshipRepoMergeLeaseService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runRepoMergeLeaseRecords(StewardshipRepoMergeLeaseService $service): int
    {
        $payload = $service->listRecords((string) $this->option('area'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-775 repo merge lease records', (string) ($p['record_count'] ?? 0));
            $this->components->twoColumnDetail('Active leases', (string) ($p['active_lease_count'] ?? 0));
            foreach ((array) ($p['active_leases'] ?? []) as $lease) {
                $this->line(sprintf(
                    '  active %s · %s · %s',
                    (string) ($lease['lease_id'] ?? ''),
                    (string) ($lease['owner'] ?? ''),
                    (string) ($lease['base_ref'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runMergeQueue(StewardshipMergeQueueService $service): int
    {
        $input = [
            'area_id' => (string) $this->option('area'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'base_ref' => (string) ($this->option('base-ref') ?: 'main'),
            'branch_refs' => (string) ($this->option('branch-ref') ?: ''),
            'auto_merge' => (bool) $this->option('auto-merge'),
            'execute_queue' => (bool) $this->option('execute-queue'),
            'allow_code_auto_merge' => (bool) $this->option('allow-code-auto-merge'),
            'max_auto_merge_files' => (int) ($this->option('max-auto-merge-files') ?: 5),
            'run_validation' => (bool) $this->option('run-validation'),
            'test_commands' => array_values(array_filter((array) $this->option('test-command'), 'is_string')),
            'record_governance' => (bool) $this->option('record-governance'),
            'record_queue' => (bool) $this->option('record-queue'),
            'lease_owner' => (string) ($this->option('lease-owner') ?: 'merge_queue_'.$this->option('area')),
            'lease_ttl_seconds' => (int) ($this->option('lease-ttl') ?: 1800),
        ];
        $queueFile = $this->readJsonFile((string) ($this->option('queue-file') ?? ''));
        if (is_array($queueFile)) {
            $input = array_merge($input, array_is_list($queueFile) ? ['branch_refs' => $queueFile] : $queueFile);
        }

        $payload = $service->run($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-772 merge queue', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Queue', (string) ($p['queue_id'] ?? ''));
            $this->components->twoColumnDetail('Branches', (string) ($p['branch_count'] ?? 0));
            $this->components->twoColumnDetail('Auto merged', (string) data_get($p, 'summary.auto_merged', 0));
            $this->components->twoColumnDetail('Review required', (string) data_get($p, 'summary.review_required', 0));
            foreach (array_slice((array) ($p['results'] ?? $p['planned_order'] ?? []), 0, 10) as $item) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($item['queue_action'] ?? 'planned'),
                    (string) ($item['governance_status'] ?? ''),
                    (string) ($item['branch_ref'] ?? ''),
                ));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
        });

        return ($payload['status'] ?? '') === StewardshipMergeQueueService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runMergeQueueRecords(StewardshipMergeQueueService $service): int
    {
        $payload = $service->listRecords((string) $this->option('area'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-772 merge queue records', (string) ($p['record_count'] ?? 0));
            foreach ((array) ($p['records'] ?? []) as $record) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($record['queue_id'] ?? ''),
                    (string) ($record['status'] ?? ''),
                    (string) ($record['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runBranchLifecycleReserve(StewardshipBranchLifecycleRegistryService $service): int
    {
        $branchRef = trim((string) ($this->option('branch-ref') ?? ''));
        if ($branchRef === '') {
            return $this->blockedResult('branch_ref_required', '--branch-ref is required for branch-lifecycle-reserve');
        }

        $payload = $service->reserve([
            'area_id' => (string) $this->option('area'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'base_ref' => (string) ($this->option('base-ref') ?: 'HEAD'),
            'branch_name' => $branchRef,
            'sandbox_id' => (string) ($this->option('sandbox-id') ?? ''),
            'owner' => (string) ($this->option('owner') ?? ''),
            'lifecycle_status' => (string) ($this->option('lifecycle-status') ?? 'reserved'),
            'record_branch_registry' => (bool) $this->option('record-branch-registry'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-770 branch lifecycle registry', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Branch', (string) data_get($p, 'branch_identity.branch_name', ''));
            $this->components->twoColumnDetail('Registry', (string) ($p['registry_id'] ?? ''));
            $this->components->twoColumnDetail('Storage', (string) ($p['registry_storage_status'] ?? 'projected'));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === StewardshipBranchLifecycleRegistryService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runBranchLifecycleRecords(StewardshipBranchLifecycleRegistryService $service): int
    {
        $payload = $service->listRecords((string) $this->option('area'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-770 branch lifecycle records', (string) ($p['record_count'] ?? 0));
            $this->components->twoColumnDetail('Active', (string) ($p['active_record_count'] ?? 0));
            foreach ((array) ($p['records'] ?? []) as $record) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($record['status'] ?? ''),
                    (string) data_get($record, 'branch_identity.branch_name', ''),
                    (string) ($record['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runBranchMergeGovernor(StewardshipBranchMergeGovernorService $service): int
    {
        $branchRef = trim((string) ($this->option('branch-ref') ?? ''));
        if ($branchRef === '') {
            return $this->blockedResult('branch_ref_required', '--branch-ref is required for branch-merge-governor');
        }

        $payload = $service->evaluate([
            'area_id' => (string) $this->option('area'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'base_ref' => (string) ($this->option('base-ref') ?: 'main'),
            'branch_ref' => $branchRef,
            'auto_merge' => (bool) $this->option('auto-merge'),
            'execute_merge' => (bool) $this->option('execute-merge'),
            'auto_merge_class' => (string) ($this->option('auto-merge-class') ?? ''),
            'allow_code_auto_merge' => (bool) $this->option('allow-code-auto-merge'),
            'max_auto_merge_files' => (int) ($this->option('max-auto-merge-files') ?: 5),
            'run_validation' => (bool) $this->option('run-validation'),
            'test_commands' => array_values(array_filter((array) $this->option('test-command'), 'is_string')),
            'record_governance' => (bool) $this->option('record-governance'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-769 branch merge governor', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Branch', (string) data_get($p, 'repo.branch_ref', ''));
            $this->components->twoColumnDetail('Base', (string) data_get($p, 'repo.base_ref', ''));
            $this->components->twoColumnDetail('Changed files', (string) data_get($p, 'classification.changed_file_count', 0));
            $this->components->twoColumnDetail('Auto-merge eligible', data_get($p, 'auto_merge_policy.eligible') ? 'yes' : 'no');
            $this->components->twoColumnDetail('Graph shape', (string) data_get($p, 'gitkraken_review_surface.graph_shape', ''));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === StewardshipBranchMergeGovernorService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runBranchMergeGovernanceRecords(StewardshipBranchMergeGovernorService $service): int
    {
        $payload = $service->listRecords((string) $this->option('area'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-769 governance records', (string) ($p['record_count'] ?? 0));
            foreach ((array) ($p['records'] ?? []) as $record) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($record['status'] ?? ''),
                    (string) data_get($record, 'repo.branch_ref', ''),
                    (string) ($record['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runLiveCycleCertification(StewardshipLiveCycleCertificationService $service): int
    {
        $payload = $service->certify([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'workspace' => (string) $this->option('workspace'),
            'execute_owner_command' => (bool) $this->option('execute-owner-command'),
            'worktree_path' => (string) ($this->option('certification-worktree') ?? ''),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-762 live cycle certification', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Mode', (string) ($p['mode'] ?? ''));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Certification', (string) ($p['certification_id'] ?? ''));
            $this->components->twoColumnDetail('Review items', (string) data_get($p, 'counts.product_mode_review_items', 0));
            $this->components->twoColumnDetail('Owner results', (string) data_get($p, 'counts.owner_results', 0));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === StewardshipLiveCycleCertificationService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runCompletionAudit(StewardshipCompletionAuditService $service): int
    {
        $payload = $service->audit([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'workspace' => (string) $this->option('workspace'),
            'worktree_path' => (string) ($this->option('certification-worktree') ?? ''),
            'include_execution_certification' => (bool) $this->option('include-execution-certification'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-763 completion audit', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Mode', (string) ($p['mode'] ?? ''));
            $this->components->twoColumnDetail('Current number', (string) ($p['current_practical_number'] ?? 0).'/'.(string) ($p['target_practical_number'] ?? 0));
            $this->components->twoColumnDetail('Proven', (string) ($p['proven_count'] ?? 0));
            $this->components->twoColumnDetail('Weak', (string) ($p['weak_count'] ?? 0));
            $this->components->twoColumnDetail('Missing', (string) ($p['missing_count'] ?? 0));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
        });

        return ($payload['status'] ?? '') === StewardshipCompletionAuditService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runNativeObraRunner(StewardshipNativeObraRunnerService $service): int
    {
        $operatorReceipts = $this->operatorReceiptsFromOption();
        if ($operatorReceipts === null) {
            return $this->blockedResult('operator_receipts_file_invalid', '--operator-receipts-file must be a readable JSON object, JSON array, or JSONL file.');
        }

        $payload = $service->run([
            'area_id' => (string) $this->option('area'),
            'enable_native_obra_runner' => (bool) $this->option('enable-native-obra-runner'),
            'record_native_obra_run' => (bool) $this->option('record-native-obra-run'),
            'provider_execution_authorized' => (bool) $this->option('provider-execution-authorized'),
            'record_scheduler_run' => (bool) $this->option('record-scheduler-run'),
            'record_continuous_cycle' => (bool) $this->option('record-continuous-cycle'),
            'force_scheduler_run' => (bool) $this->option('force-scheduler-run'),
            'kill_switch' => (bool) $this->option('kill-switch'),
            'min_interval_seconds' => (int) $this->option('min-interval-seconds'),
            'operator_receipts' => $operatorReceipts,
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-764 native Obra runner', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Run', (string) ($p['native_obra_run_id'] ?? ''));
            $this->components->twoColumnDetail('Scheduler', (string) ($p['scheduler_run_status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Active operation', (string) ($p['active_operation_status'] ?? 'not_available'));
            $this->components->twoColumnDetail('Native Obra handoffs', (string) ($p['native_obra_handoff_count'] ?? 0));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_native_obra_run_requested'] ?? false)) ? 'requested' : 'projection-only');
            $this->components->twoColumnDetail('Codex app automation', ((bool) data_get($p, 'claim_policy.codex_app_automation_used', false)) ? 'used' : 'not used');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === StewardshipNativeObraRunnerService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

}

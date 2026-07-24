<?php

declare(strict_types=1);

namespace App\Console\Commands\SoftwareCompanyStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveAllocationHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveRecommendationService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\ExecutiveDecisionInboxSurfaceService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipLoopService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipRecurringSchedulerService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayReadinessService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\ContinuousStewardshipDayStartService;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipHealthModelService;
use App\Services\Ai\SoftwareCompanyStewardship\PortfolioStewardship\PortfolioStewardshipInboxService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\NewAreaProposalGateService;
use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfExpandingSoftwareCompanyService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionDecisionLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipEvolutionReadModelService;
use App\Support\YesNo;

trait PortfolioExecutiveEvolutionSection
{
    private function runContinuousStewardshipLoop(AtlasContinuousStewardshipLoopService $service): int
    {
        $operatorReceipts = $this->operatorReceiptsFromOption();
        if ($operatorReceipts === null) {
            return $this->blockedResult('operator_receipts_file_invalid', '--operator-receipts-file must be a readable JSON object, JSON array, or JSONL file.');
        }

        $payload = $service->tick([
            'area_id' => (string) $this->option('area'),
            'enabled' => (bool) $this->option('enable-continuous-loop'),
            'record_continuous_cycle' => (bool) $this->option('record-continuous-cycle'),
            'force_continuous_tick' => (bool) $this->option('force-continuous-tick'),
            'kill_switch' => (bool) $this->option('kill-switch'),
            'min_interval_seconds' => (int) $this->option('min-interval-seconds'),
            'operator_receipts' => $operatorReceipts,
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-745 Continuous Stewardship Loop', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Tick', (string) ($p['tick_id'] ?? ''));
            $this->components->twoColumnDetail('AP-744 operation', (string) ($p['active_operation_status'] ?? 'not_run'));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_continuous_cycle_requested'] ?? false)) ? 'requested' : 'projection-only');
            $this->components->twoColumnDetail('Enabled', YesNo::format((bool) data_get($p, 'policy.enabled', false)));
            $this->components->twoColumnDetail('Kill switch', ((bool) data_get($p, 'policy.kill_switch_active', false)) ? 'active' : 'clear');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return in_array((string) ($payload['status'] ?? ''), [
            AtlasContinuousStewardshipLoopService::STATUS_BLOCKED,
            AtlasContinuousStewardshipLoopService::STATUS_LOCKED,
        ], true)
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runContinuousStewardshipScheduler(AtlasContinuousStewardshipRecurringSchedulerService $service): int
    {
        $operatorReceipts = $this->operatorReceiptsFromOption();
        if ($operatorReceipts === null) {
            return $this->blockedResult('operator_receipts_file_invalid', '--operator-receipts-file must be a readable JSON object, JSON array, or JSONL file.');
        }

        $payload = $service->run([
            'area_id' => (string) $this->option('area'),
            'scheduler_id' => (string) $this->option('scheduler-id'),
            'enabled' => (bool) $this->option('enable-continuous-scheduler'),
            'continuous_loop_enabled' => (bool) $this->option('enable-continuous-scheduler'),
            'record_scheduler_run' => (bool) $this->option('record-scheduler-run'),
            'record_continuous_cycle' => (bool) $this->option('record-continuous-cycle'),
            'force_scheduler_run' => (bool) $this->option('force-scheduler-run'),
            'kill_switch' => (bool) $this->option('kill-switch'),
            'pause_until' => (string) ($this->option('pause-until') ?? ''),
            'min_interval_seconds' => (int) $this->option('min-interval-seconds'),
            'operator_receipts' => $operatorReceipts,
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-746 Continuous Stewardship Scheduler', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Scheduler', (string) ($p['scheduler_id'] ?? ''));
            $this->components->twoColumnDetail('Run', (string) ($p['scheduler_run_id'] ?? ''));
            $this->components->twoColumnDetail('AP-745 tick', (string) ($p['tick_status'] ?? data_get($p, 'continuous_loop.status', 'not_run')));
            $this->components->twoColumnDetail('Scheduler record', ((bool) ($p['record_scheduler_run_requested'] ?? false)) ? 'requested' : 'projection-only');
            $this->components->twoColumnDetail('Continuous record', ((bool) ($p['record_continuous_cycle_requested'] ?? false)) ? 'requested' : 'projection-only');
            $this->components->twoColumnDetail('Scheduler enabled', YesNo::format((bool) data_get($p, 'policy.scheduler_enabled', false)));
            $this->components->twoColumnDetail('Kill switch', ((bool) data_get($p, 'policy.kill_switch_active', false)) ? 'active' : 'clear');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return in_array((string) ($payload['status'] ?? ''), [
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_BLOCKED,
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_LOCKED,
        ], true)
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runContinuous24hReadiness(ContinuousStewardshipDayReadinessService $service): int
    {
        $maxRunsPerDay = $this->option('max-runs-per-day');
        $lockTtl = $this->option('runner-lock-ttl-seconds');

        $payload = $service->assess([
            'area_id' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'duration_hours' => (int) ($this->option('duration-hours') ?: 24),
            'enabled' => (bool) $this->option('enable-continuous-runner'),
            'global_kill_switch' => (bool) $this->option('kill-switch'),
            'area_kill_switch' => (bool) $this->option('area-kill-switch'),
            'pause_until' => (string) ($this->option('pause-until') ?? ''),
            'min_interval_seconds' => (int) $this->option('min-interval-seconds'),
            'max_runs_per_day' => ($maxRunsPerDay !== null && $maxRunsPerDay !== '') ? (int) $maxRunsPerDay : null,
            'lock_ttl_seconds' => ($lockTtl !== null && $lockTtl !== '') ? (int) $lockTtl : null,
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-777 24h readiness', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Focus', (string) ($p['focus'] ?? ''));
            $this->components->twoColumnDetail('Runner', (string) data_get($p, 'runner_status.status', 'unknown'));
            $this->components->twoColumnDetail('Branch stack', (string) data_get($p, 'branch_system_certification.status', 'unknown'));
            $this->components->twoColumnDetail('Budget', (string) data_get($p, 'runner_status.budget_status', '').' ('.(string) data_get($p, 'runner_status.budget.used_today', 0).'/'.(string) data_get($p, 'runner_status.budget.max_runs_per_day', 0).')');
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
            if (($p['operator_start_command'] ?? '') !== '') {
                $this->line('  start: '.(string) $p['operator_start_command']);
            }
        });

        return ($payload['status'] ?? '') === ContinuousStewardshipDayReadinessService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runContinuous24hStart(ContinuousStewardshipDayStartService $service): int
    {
        $operatorReceipts = $this->operatorReceiptsFromOption();
        if ($operatorReceipts === null) {
            return $this->blockedResult('operator_receipts_file_invalid', '--operator-receipts-file must be a readable JSON object, JSON array, or JSONL file.');
        }

        $maxRunsPerDay = $this->option('max-runs-per-day');
        $lockTtl = $this->option('runner-lock-ttl-seconds');

        $payload = $service->start([
            'area_id' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'duration_hours' => (int) ($this->option('duration-hours') ?: 24),
            'enabled' => (bool) $this->option('enable-continuous-runner'),
            'execute_first_tick' => (bool) $this->option('execute-first-tick'),
            'record' => (bool) $this->option('record'),
            'global_kill_switch' => (bool) $this->option('kill-switch'),
            'area_kill_switch' => (bool) $this->option('area-kill-switch'),
            'pause_until' => (string) ($this->option('pause-until') ?? ''),
            'min_interval_seconds' => (int) $this->option('min-interval-seconds'),
            'max_runs_per_day' => ($maxRunsPerDay !== null && $maxRunsPerDay !== '') ? (int) $maxRunsPerDay : null,
            'lock_ttl_seconds' => ($lockTtl !== null && $lockTtl !== '') ? (int) $lockTtl : null,
            'operator_receipts' => $operatorReceipts,
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-778 24h start', (string) ($p['final_status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Focus', (string) ($p['focus'] ?? ''));
            $this->components->twoColumnDetail('Tick', (string) ($p['tick_status'] ?? 'not_attempted'));
            $this->components->twoColumnDetail('Recorded', (string) ($p['start_storage_status'] ?? 'projected'));
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            if (($p['next_operator_action'] ?? '') !== '') {
                $this->line('  next: '.(string) $p['next_operator_action']);
            }
            if (($p['operator_next_command'] ?? '') !== '') {
                $this->line('  command: '.(string) $p['operator_next_command']);
            }
        });

        return ($payload['final_status'] ?? '') === ContinuousStewardshipDayStartService::STATUS_FIRST_TICK_EXECUTED
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function runContinuous24hStartList(ContinuousStewardshipDayStartService $service): int
    {
        $payload = [
            'schema_version' => 'atlas.software_company_stewardship.continuous_24h_start_list.v1',
            'status' => 'ok',
            'area_id' => (string) $this->option('area'),
            'records' => $service->list(['area_id' => (string) $this->option('area')]),
        ];

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-778 start records', (string) count((array) ($p['records'] ?? [])));
        });

        return self::SUCCESS;
    }

    private function runContinuous24hStartReplay(ContinuousStewardshipDayStartService $service): int
    {
        $receiptId = trim((string) ($this->option('start-receipt-id') ?? ''));
        if ($receiptId === '') {
            return $this->blockedResult('start_receipt_id_required', '--start-receipt-id is required for continuous-24h-start-replay.');
        }

        $record = $service->replay($receiptId, ['area_id' => (string) $this->option('area')]);
        if ($record === null) {
            return $this->blockedResult('start_receipt_not_found', 'No AP-778 start receipt matched '.$receiptId.'.');
        }

        $this->emit($record, function (array $p): void {
            $this->components->twoColumnDetail('AP-778 replay', (string) ($p['final_status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Receipt', (string) ($p['start_receipt_id'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function runPortfolioHealth(PortfolioStewardshipHealthModelService $service): int
    {
        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-733 portfolio health', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Score', (string) data_get($p, 'portfolio_health.score', 0).' · '.(string) data_get($p, 'portfolio_health.band', 'unknown'));
            $this->components->twoColumnDetail('Lowest area', (string) data_get($p, 'portfolio_health.lowest_health_area', ''));
            foreach ($p['rebalance_candidates'] ?? [] as $candidate) {
                $this->line(sprintf(
                    '  %s · %s · priority=%s',
                    (string) ($candidate['candidate_id'] ?? ''),
                    (string) ($candidate['target_area'] ?? ''),
                    (string) ($candidate['priority_score'] ?? ''),
                ));
            }
            foreach ($p['blockers'] ?? [] as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
        });

        return ($payload['status'] ?? '') === PortfolioStewardshipHealthModelService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runPortfolioHealthRecord(PortfolioStewardshipHealthModelService $service): int
    {
        $payload = $service->record([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-733 snapshot', (string) ($p['snapshot_id'] ?? ''));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Score', (string) data_get($p, 'portfolio_health.score', 0).' · '.(string) data_get($p, 'portfolio_health.band', 'unknown'));
            $this->components->twoColumnDetail('Recorded', (string) ($p['recorded_at'] ?? ''));
        });

        return ($payload['status'] ?? '') === PortfolioStewardshipHealthModelService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runPortfolioHealthSnapshots(PortfolioStewardshipHealthModelService $service): int
    {
        $payload = $service->listSnapshots((string) $this->option('portfolio'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-733 snapshots', (string) ($p['snapshot_count'] ?? 0));
            foreach ($p['snapshots'] ?? [] as $snapshot) {
                $this->line(sprintf(
                    '  %s · %s · %s · %s',
                    (string) ($snapshot['snapshot_id'] ?? ''),
                    (string) ($snapshot['portfolio_id'] ?? ''),
                    (string) ($snapshot['score'] ?? ''),
                    (string) ($snapshot['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runPortfolioHealthReplay(PortfolioStewardshipHealthModelService $service): int
    {
        $snapshotId = trim((string) $this->option('snapshot-id'));
        if ($snapshotId === '') {
            return $this->blockedResult('snapshot_id_required', '--snapshot-id is required for portfolio-health-replay');
        }

        $payload = $service->replay($snapshotId);
        if ($payload === null) {
            return $this->blockedResult('snapshot_not_found', $snapshotId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-733 replay', (string) ($p['snapshot_id'] ?? ''));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Score', (string) data_get($p, 'portfolio_health.score', 0).' · '.(string) data_get($p, 'portfolio_health.band', 'unknown'));
        });

        return self::SUCCESS;
    }

    private function runPortfolioInbox(PortfolioStewardshipInboxService $service): int
    {
        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'snapshot_id' => (string) $this->option('snapshot-id'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-734 portfolio inbox', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Items', (string) ($p['item_count'] ?? 0));
            foreach ($p['items'] ?? [] as $item) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($item['item_id'] ?? ''),
                    (string) ($item['target_area'] ?? ''),
                    (string) ($item['risk_level'] ?? ''),
                ));
            }
        });

        return ($payload['status'] ?? '') === PortfolioStewardshipInboxService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runPortfolioInboxRecord(PortfolioStewardshipInboxService $service): int
    {
        $payload = $service->record([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'snapshot_id' => (string) $this->option('snapshot-id'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-734 inbox', (string) ($p['inbox_id'] ?? ''));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Items', (string) ($p['item_count'] ?? 0));
            $this->components->twoColumnDetail('Recorded', (string) ($p['recorded_at'] ?? ''));
        });

        return ($payload['status'] ?? '') === PortfolioStewardshipInboxService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runPortfolioInboxList(PortfolioStewardshipInboxService $service): int
    {
        $payload = $service->listInboxes((string) $this->option('portfolio'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-734 inboxes', (string) ($p['inbox_count'] ?? 0));
            foreach ($p['inboxes'] ?? [] as $inbox) {
                $this->line(sprintf(
                    '  %s · %s · items=%s · %s',
                    (string) ($inbox['inbox_id'] ?? ''),
                    (string) ($inbox['portfolio_id'] ?? ''),
                    (string) ($inbox['item_count'] ?? 0),
                    (string) ($inbox['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runPortfolioInboxReplay(PortfolioStewardshipInboxService $service): int
    {
        $inboxId = trim((string) $this->option('inbox-id'));
        if ($inboxId === '') {
            return $this->blockedResult('inbox_id_required', '--inbox-id is required for portfolio-inbox-replay');
        }

        $payload = $service->replay($inboxId);
        if ($payload === null) {
            return $this->blockedResult('inbox_not_found', $inboxId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-734 replay', (string) ($p['inbox_id'] ?? ''));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Items', (string) ($p['item_count'] ?? 0));
        });

        return self::SUCCESS;
    }

    private function runPortfolioInboxDecision(PortfolioStewardshipInboxService $service): int
    {
        try {
            $payload = $service->decide([
                'area_id' => (string) $this->option('area'),
                'portfolio_id' => (string) $this->option('portfolio'),
                'snapshot_id' => (string) $this->option('snapshot-id'),
                'inbox_id' => (string) $this->option('inbox-id'),
                'item_id' => (string) $this->option('item-id'),
                'operator_actor' => (string) $this->option('actor'),
                'decision' => (string) $this->option('decision'),
                'target_id' => (string) $this->option('target-id'),
                'target_hash' => (string) $this->option('target-hash'),
                'risk' => (string) $this->option('risk'),
                'rationale' => (string) $this->option('rationale'),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->blockedResult('portfolio_inbox_decision_blocked', $e->getMessage());
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-734 decision', (string) ($p['decision_id'] ?? ''));
            $this->components->twoColumnDetail('Target', (string) ($p['target_type'] ?? '').' · '.(string) ($p['target_id'] ?? ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['decision'] ?? ''));
            $this->components->twoColumnDetail('Executed', YesNo::format((bool) ($p['executed'] ?? false)));
        });

        return self::SUCCESS;
    }

    private function runExecutiveRecommendations(AutonomousExecutiveRecommendationService $service): int
    {
        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'inbox_id' => (string) $this->option('inbox-id'),
            'snapshot_id' => (string) $this->option('snapshot-id'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-735 executive recommendations', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Recommendations', (string) ($p['recommendation_count'] ?? 0));
            foreach ($p['recommendations'] ?? [] as $recommendation) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($recommendation['recommendation_id'] ?? ''),
                    (string) ($recommendation['target_area'] ?? ''),
                    (string) data_get($recommendation, 'risk_analysis.risk_level', ''),
                ));
            }
        });

        return ($payload['status'] ?? '') === AutonomousExecutiveRecommendationService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runExecutiveRecommendationRecord(AutonomousExecutiveRecommendationService $service): int
    {
        $payload = $service->record([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'inbox_id' => (string) $this->option('inbox-id'),
            'snapshot_id' => (string) $this->option('snapshot-id'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-735 pack', (string) ($p['pack_id'] ?? ''));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Recommendations', (string) ($p['recommendation_count'] ?? 0));
            $this->components->twoColumnDetail('Recorded', (string) ($p['recorded_at'] ?? ''));
        });

        return ($payload['status'] ?? '') === AutonomousExecutiveRecommendationService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runExecutiveRecommendationList(AutonomousExecutiveRecommendationService $service): int
    {
        $payload = $service->listPacks((string) $this->option('portfolio'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-735 packs', (string) ($p['pack_count'] ?? 0));
            foreach ($p['packs'] ?? [] as $pack) {
                $this->line(sprintf(
                    '  %s · %s · recommendations=%s · %s',
                    (string) ($pack['pack_id'] ?? ''),
                    (string) ($pack['portfolio_id'] ?? ''),
                    (string) ($pack['recommendation_count'] ?? 0),
                    (string) ($pack['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runExecutiveRecommendationReplay(AutonomousExecutiveRecommendationService $service): int
    {
        $packId = trim((string) $this->option('pack-id'));
        if ($packId === '') {
            return $this->blockedResult('pack_id_required', '--pack-id is required for executive-recommendation-replay');
        }

        $payload = $service->replay($packId);
        if ($payload === null) {
            return $this->blockedResult('executive_recommendation_pack_not_found', $packId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-735 replay', (string) ($p['pack_id'] ?? ''));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Recommendations', (string) ($p['recommendation_count'] ?? 0));
        });

        return self::SUCCESS;
    }

    private function runExecutiveRecommendationDecision(AutonomousExecutiveRecommendationService $service): int
    {
        try {
            $payload = $service->decide([
                'area_id' => (string) $this->option('area'),
                'portfolio_id' => (string) $this->option('portfolio'),
                'inbox_id' => (string) $this->option('inbox-id'),
                'pack_id' => (string) $this->option('pack-id'),
                'recommendation_id' => (string) $this->option('recommendation-id'),
                'operator_actor' => (string) $this->option('actor'),
                'decision' => (string) $this->option('decision'),
                'target_id' => (string) $this->option('target-id'),
                'target_hash' => (string) $this->option('target-hash'),
                'risk' => (string) $this->option('risk'),
                'rationale' => (string) $this->option('rationale'),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->blockedResult('executive_recommendation_decision_blocked', $e->getMessage());
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-735 decision', (string) ($p['decision_id'] ?? ''));
            $this->components->twoColumnDetail('Target', (string) ($p['target_type'] ?? '').' · '.(string) ($p['target_id'] ?? ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['decision'] ?? ''));
            $this->components->twoColumnDetail('Executed', YesNo::format((bool) ($p['executed'] ?? false)));
        });

        return self::SUCCESS;
    }

    private function runExecutiveDecisionInbox(ExecutiveDecisionInboxSurfaceService $service): int
    {
        $payload = $service->project((string) $this->option('portfolio'), [
            'area_id' => (string) $this->option('area'),
            'pack_id' => (string) $this->option('pack-id'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-736 executive decision inbox', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Items', (string) ($p['item_count'] ?? 0));
            foreach ($p['items'] ?? [] as $item) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($item['source_recommendation_id'] ?? ''),
                    (string) ($item['target_area'] ?? ''),
                    (string) ($item['status'] ?? ''),
                ));
            }
        });

        return ($payload['status'] ?? '') === ExecutiveDecisionInboxSurfaceService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runExecutiveAllocationHandoff(AutonomousExecutiveAllocationHandoffService $service): int
    {
        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'pack_id' => (string) $this->option('pack-id'),
            'recommendation_id' => (string) $this->option('recommendation-id'),
            'record_allocation_handoff' => (bool) $this->option('record-allocation-handoff'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-752 executive allocation handoff', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? ''));
            $this->components->twoColumnDetail('Recommendation', (string) ($p['source_recommendation_id'] ?? ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['source_decision_id'] ?? ''));
            $this->components->twoColumnDetail('Packets', (string) ($p['allocation_handoff_count'] ?? 0));
            $this->components->twoColumnDetail('Recorded', ((bool) ($p['record_allocation_handoff_requested'] ?? false)) ? 'requested' : 'projection-only');
            foreach ((array) ($p['allocation_handoff_packets'] ?? []) as $packet) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($packet['handoff_packet_id'] ?? ''),
                    (string) ($packet['target_owner'] ?? ''),
                    (string) ($packet['recommended_action'] ?? ''),
                ));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === AutonomousExecutiveAllocationHandoffService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runExecutiveAllocationHandoffList(AutonomousExecutiveAllocationHandoffService $service): int
    {
        $payload = $service->listHandoffs((string) $this->option('portfolio'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-752 handoffs', (string) ($p['handoff_count'] ?? 0));
            foreach ((array) ($p['handoffs'] ?? []) as $handoff) {
                $this->line(sprintf(
                    '  %s · %s · %s · %s',
                    (string) ($handoff['handoff_packet_id'] ?? ''),
                    (string) ($handoff['target_owner'] ?? ''),
                    (string) ($handoff['recommended_action'] ?? ''),
                    (string) ($handoff['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runExecutiveAllocationHandoffReplay(AutonomousExecutiveAllocationHandoffService $service): int
    {
        $handoffId = trim((string) $this->option('handoff-id'));
        if ($handoffId === '') {
            return $this->blockedResult('handoff_id_required', '--handoff-id is required for executive-allocation-handoff-replay');
        }

        $payload = $service->replay($handoffId);
        if ($payload === null) {
            return $this->blockedResult('executive_allocation_handoff_not_found', $handoffId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-752 replay', (string) ($p['handoff_packet_id'] ?? ''));
            $this->components->twoColumnDetail('Target owner', (string) ($p['target_owner'] ?? ''));
            $this->components->twoColumnDetail('Action', (string) ($p['recommended_action'] ?? ''));
            $this->components->twoColumnDetail('Status', (string) ($p['handoff_status'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function runEvolution(StewardshipEvolutionReadModelService $evolution, ?string $section = null): int
    {
        $payload = $evolution->project(['area_id' => (string) $this->option('area')]);
        $output = $section !== null && is_array($payload[$section] ?? null)
            ? $payload[$section] + [
                'parent_schema_version' => $payload['schema_version'] ?? StewardshipEvolutionReadModelService::REPORT_SCHEMA,
                'parent_report_hash' => $payload['report_hash'] ?? null,
                'claim_policy' => $payload['claim_policy'] ?? [],
            ]
            : $payload;

        $this->emit($output, function (array $p) use ($section): void {
            $this->components->twoColumnDetail('Stewardship Stack', $section ?? 'evolution ladder');
            $this->components->twoColumnDetail('Schema', (string) ($p['schema_version'] ?? ''));
            $this->components->twoColumnDetail('Status', (string) ($p['status'] ?? 'unknown'));

            if ($section === null) {
                $stack = is_array($p['stewardship_stack'] ?? null) ? $p['stewardship_stack'] : [];
                $this->components->twoColumnDetail('Continuous Loop', (string) ($stack['continuous_loop_role'] ?? '24h governed motor'));
                $this->components->twoColumnDetail('Stack ceiling', (string) ($stack['stack_ceiling'] ?? 'Self-Expanding Software Company'));
                $executive = is_array($p['autonomous_executive'] ?? null) ? $p['autonomous_executive'] : [];
                $primary = is_array($executive['primary_recommendation'] ?? null) ? $executive['primary_recommendation'] : [];
                $this->components->twoColumnDetail('Executive target', (string) ($primary['target_area'] ?? '?'));
                $self = is_array($p['self_expanding_software_company'] ?? null) ? $p['self_expanding_software_company'] : [];
                $this->components->twoColumnDetail('Expansion proposals', (string) ($self['proposal_count'] ?? 0));
            }
        });

        return ($payload['status'] ?? '') === StewardshipEvolutionReadModelService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runNewAreaProposalGate(NewAreaProposalGateService $service): int
    {
        $payload = $service->evaluate([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'proposal_id' => (string) $this->option('proposal-id'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-737 new area proposal gate', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Items', (string) ($p['gate_item_count'] ?? 0));
            foreach ($p['gate_items'] ?? [] as $item) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($item['proposal_id'] ?? ''),
                    (string) ($item['candidate_area'] ?? ''),
                    (string) ($item['gate_status'] ?? ''),
                ));
            }
        });

        return ($payload['status'] ?? '') === NewAreaProposalGateService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runNewAreaProposalDecision(NewAreaProposalGateService $service): int
    {
        try {
            $payload = $service->decide([
                'area_id' => (string) $this->option('area'),
                'portfolio_id' => (string) $this->option('portfolio'),
                'proposal_id' => (string) $this->option('proposal-id'),
                'operator_actor' => (string) $this->option('actor'),
                'decision' => (string) $this->option('decision'),
                'risk' => (string) $this->option('risk'),
                'rationale' => (string) $this->option('rationale'),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->blockedResult('new_area_proposal_decision_blocked', $e->getMessage());
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-737 decision', (string) ($p['decision_id'] ?? ''));
            $this->components->twoColumnDetail('Target', (string) ($p['target_type'] ?? '').' · '.(string) ($p['target_id'] ?? ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['decision'] ?? ''));
            $this->components->twoColumnDetail('Next', (string) ($p['next_allowed_action'] ?? ''));
            $this->components->twoColumnDetail('Executed', YesNo::format((bool) ($p['executed'] ?? false)));
        });

        return self::SUCCESS;
    }

    private function runSelfExpandingV0(SelfExpandingSoftwareCompanyService $service): int
    {
        $payload = $service->project([
            'area_id' => (string) $this->option('area'),
            'portfolio_id' => (string) $this->option('portfolio'),
            'proposal_id' => (string) $this->option('proposal-id'),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-738 Self-Expanding v0', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Portfolio', (string) ($p['portfolio_id'] ?? ''));
            $this->components->twoColumnDetail('Layer', (string) ($p['layer'] ?? ''));
            $summary = is_array($p['expansion_summary'] ?? null) ? $p['expansion_summary'] : [];
            $this->components->twoColumnDetail('Candidates', (string) ($summary['total_candidates'] ?? 0));
            $this->components->twoColumnDetail('New domains', (string) ($summary['new_domain_candidates'] ?? 0));
            $this->components->twoColumnDetail('Existing handoffs', (string) ($summary['existing_capability_handoffs'] ?? 0));
            foreach (data_get($p, 'operator_inbox.items', []) as $item) {
                $this->line(sprintf(
                    '  %s · %s · %s',
                    (string) ($item['proposal_id'] ?? ''),
                    (string) ($item['candidate_area'] ?? ''),
                    (string) ($item['recommended_operator_action'] ?? ''),
                ));
            }
        });

        return ($payload['status'] ?? '') === SelfExpandingSoftwareCompanyService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function runEvolutionDecision(StewardshipEvolutionDecisionLedgerService $ledger): int
    {
        try {
            $payload = $ledger->record([
                'area_id' => (string) $this->option('area'),
                'operator_actor' => (string) $this->option('actor'),
                'decision' => (string) $this->option('decision'),
                'target_type' => (string) $this->option('target-type'),
                'target_id' => (string) $this->option('target-id'),
                'target_hash' => (string) $this->option('target-hash'),
                'risk' => (string) $this->option('risk'),
                'rationale' => (string) $this->option('rationale'),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->blockedResult('evolution_decision_blocked', $e->getMessage());
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-731 decision', (string) ($p['decision_id'] ?? ''));
            $this->components->twoColumnDetail('Target', (string) ($p['target_type'] ?? '').' · '.(string) ($p['target_id'] ?? ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['decision'] ?? ''));
            $this->components->twoColumnDetail('Executed', YesNo::format((bool) ($p['executed'] ?? false)));
        });

        return self::SUCCESS;
    }

    private function runEvolutionDecisionList(StewardshipEvolutionDecisionLedgerService $ledger): int
    {
        $payload = $ledger->listDecisions((string) $this->option('area'));

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-731 decisions', (string) ($p['decision_count'] ?? 0));
            foreach ($p['decisions'] ?? [] as $decision) {
                $this->line(sprintf(
                    '  %s · %s · %s · %s',
                    (string) ($decision['decision_id'] ?? ''),
                    (string) ($decision['target_type'] ?? ''),
                    (string) ($decision['decision'] ?? ''),
                    (string) ($decision['operator_actor'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runEvolutionDecisionReplay(StewardshipEvolutionDecisionLedgerService $ledger): int
    {
        $decisionId = trim((string) $this->option('decision-id'));
        if ($decisionId === '') {
            return $this->blockedResult('decision_id_required', '--decision-id is required for evolution-replay');
        }

        $payload = $ledger->replay($decisionId);
        if ($payload === null) {
            return $this->blockedResult('decision_not_found', $decisionId);
        }

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-731 replay', (string) ($p['decision_id'] ?? ''));
            $this->components->twoColumnDetail('Target', (string) ($p['target_type'] ?? '').' · '.(string) ($p['target_id'] ?? ''));
            $this->components->twoColumnDetail('Decision', (string) ($p['decision'] ?? ''));
        });

        return self::SUCCESS;
    }

}

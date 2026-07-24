<?php

declare(strict_types=1);

namespace App\Console\Commands\SoftwareCompanyStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\CycleQualityScoreService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopChaosCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopInvariantHarnessService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopLedgerArchiveService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopPreflightCycleFirewallService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LongHorizonLoopDeliveryLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopPostCycleAuditorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopFlightRecorderService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopDeterministicSimulatorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TransactionalCycleStateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopResourceGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\BacklogDepthGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\BacklogRegenerationEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopQualityDriftDetectorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AlwaysOnLoopSupervisorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\SelfHealingMaintenanceWindowService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ProviderReliabilityLayerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopDisasterRecoveryService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopProcessIsolationStatusService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LongRunCertificationLadderService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Loop24hCertificationHarnessService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TenCycleReadinessGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopAutonomyCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipAutonomyEnvelopeService;
use App\Support\YesNo;

trait LoopAssuranceSection
{
    private function runReliable24hObservability(AutonomousEvolutionSessionReadModelService $service): int
    {
        $payload = $service->project24hObservability([
            'area_id' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
        ]);

        $this->emit($payload, function (array $p): void {
            $metrics = is_array($p['metrics'] ?? null) ? $p['metrics'] : [];
            $this->components->twoColumnDetail('AP-790 24h observability', (string) ($p['schema_version'] ?? ''));
            $this->components->twoColumnDetail('Cycles', (string) ($metrics['cycles_total'] ?? 0));
            $this->components->twoColumnDetail('Merges', (string) ($metrics['merges_total'] ?? 0));
            $this->components->twoColumnDetail('Blocked', (string) ($metrics['blocked_total'] ?? 0));
            $this->components->twoColumnDetail('Success rate', (string) ($metrics['success_rate'] ?? 0));
            $this->components->twoColumnDetail('Merge/hour', (string) ($metrics['merge_rate_per_hour'] ?? 0));
            $this->components->twoColumnDetail('Inbox summaries', (string) count((array) ($p['cycle_inbox_summaries'] ?? [])));
            $this->components->twoColumnDetail('Active worktrees', (string) count((array) ($p['active_worktrees'] ?? [])));
            $this->components->twoColumnDetail('Quarantined', (string) ($p['quarantined_count'] ?? 0));
        });

        return self::SUCCESS;
    }

    private function runLoop24hReadiness(Loop24hCertificationHarnessService $service): int
    {
        $payload = $service->assess24hTestReadiness([
            'area_id' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-790 24h readiness', (string) ($p['status'] ?? 'unknown'));
            foreach ((array) ($p['checks'] ?? []) as $name => $check) {
                if (! is_array($check)) {
                    continue;
                }
                $this->components->twoColumnDetail((string) $name, ((bool) ($check['ok'] ?? false)) ? 'ok' : 'blocked');
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['next_actions'] ?? []) as $action) {
                $this->line('  next: '.(string) $action);
            }
        });

        return ($payload['status'] ?? '') === Loop24hCertificationHarnessService::STATUS_READY_FOR_24H_TEST
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function runTenCycleReadiness(TenCycleReadinessGovernorService $service): int
    {
        // Run the two read-only validations and feed their result in as gate
        // inputs. Failures/exceptions degrade to null (proof-command warning),
        // never a crash.
        $payload = $service->assess([
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'allow_cleanup_plan' => (bool) $this->option('allow-cleanup-plan'),
            'include_product_mode' => (bool) $this->option('include-product-mode'),
            'include_provider_probe' => (bool) $this->option('include-provider-probe'),
            'include_branch_audit' => (bool) $this->option('include-branch-audit'),
            'architecture_validate' => $this->safeValidation('atlas:ai:architecture-validate', ['--json' => true]),
            'docs_health' => $this->safeValidation('atlas:engineering:knowledge', ['action' => 'docs-health', '--json' => true]),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-805 10-cycle readiness', (string) ($p['status'] ?? 'unknown'));
            foreach ((array) ($p['gates'] ?? []) as $name => $gate) {
                if (! is_array($gate)) {
                    continue;
                }
                $this->components->twoColumnDetail((string) $name, ((bool) ($gate['ok'] ?? false)) ? 'ok' : (((bool) ($gate['hard'] ?? false)) ? 'BLOCKED' : 'warn'));
            }
            foreach ((array) ($p['blockers'] ?? []) as $blocker) {
                $this->warn('  blocker: '.(string) $blocker);
            }
            foreach ((array) ($p['warnings'] ?? []) as $warning) {
                $this->line('  warning: '.(string) $warning);
            }
            $this->line('  run 10 cycles with: '.(string) ($p['recommended_command_for_10_cycle_run'] ?? ''));
        });

        $ready = ($payload['status'] ?? '') === TenCycleReadinessGovernorService::STATUS_READY;

        return ((bool) $this->option('strict') && ! $ready) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopAutonomyCertify(LoopAutonomyCertificationService $service): int
    {
        $payload = $service->certify([
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'target_mode' => (string) ($this->option('target-mode') ?: LoopAutonomyCertificationService::MODE_AAEOS_DEV_LANE),
            'include_product_mode' => (bool) $this->option('include-product-mode'),
            'include_provider_probe' => (bool) $this->option('include-provider-probe'),
            'include_branch_audit' => (bool) $this->option('include-branch-audit'),
            'architecture_validate' => $this->safeValidation('atlas:ai:architecture-validate', ['--json' => true]),
            'docs_health' => $this->safeValidation('atlas:engineering:knowledge', ['action' => 'docs-health', '--json' => true]),
        ]);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('AP-806 autonomy ('.((string) ($p['target_mode'] ?? '')).')', sprintf('%s · score %.2f (%s)', (string) ($p['verdict'] ?? ''), (float) ($p['autonomy_score'] ?? 0), (string) ($p['autonomy_band'] ?? '')));
            foreach ((array) ($p['stages'] ?? []) as $key => $stage) {
                if (! is_array($stage) || ($stage['relevant_to_mode'] ?? false) !== true) {
                    continue;
                }
                $this->components->twoColumnDetail('  '.(string) $key, (string) ($stage['state'] ?? ''));
            }
            foreach ((array) ($p['blockers_by_impact'] ?? []) as $b) {
                if (is_array($b)) {
                    $this->warn('  blocker['.(string) ($b['impact_weight'] ?? '').']: '.(string) ($b['stage'] ?? '').' — '.(string) ($b['remediation'] ?? ''));
                }
            }
            $slice = (array) ($p['next_executable_slice'] ?? []);
            $this->line('  next slice: '.(string) ($slice['title'] ?? ''));
            $cmd = (array) ($p['most_autonomous_command_now'] ?? []);
            $this->line('  most autonomous now ('.(string) ($cmd['mode'] ?? '').'): '.(string) ($cmd['command'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function runLoopAutonomyEnvelope(StewardshipAutonomyEnvelopeService $service): int
    {
        $sub = strtolower(trim((string) ($this->option('envelope-action') ?: 'show')));
        $area = (string) $this->option('area');
        $focus = (string) $this->option('focus');
        $operator = (string) ($this->option('operator-actor') ?: $this->option('actor') ?: '');
        $csv = static fn (?string $v): array => $v === null || trim($v) === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $v)), static fn (string $s): bool => $s !== ''));

        if ($sub === 'show') {
            $payload = $service->show($area, $focus);
            $this->emit($payload, function (array $p): void {
                $this->components->twoColumnDetail('AP-806 envelope ('.(string) ($p['focus'] ?? '').')', ((bool) ($p['armed'] ?? false)) ? 'ARMED' : 'not armed');
            });

            return self::SUCCESS;
        }

        if ($sub === 'disarm') {
            $payload = $service->disarm(['area_id' => $area, 'focus' => $focus, 'operator_actor' => $operator]);
            $this->emit($payload, function (array $p): void {
                $this->components->twoColumnDetail('AP-806 envelope disarm', (string) ($p['status'] ?? ''));
            });

            return (string) ($payload['status'] ?? '') === StewardshipAutonomyEnvelopeService::STATUS_DISARMED ? self::SUCCESS : self::FAILURE;
        }

        if ($sub === 'arm') {
            $payload = $service->arm([
                'area_id' => $area,
                'focus' => $focus,
                'operator_actor' => $operator,
                'merge_target' => (string) ($this->option('merge-target') ?: 'integration_lane'),
                'admit_cross_system' => (bool) $this->option('admit-cross-system'),
                'risk_ceiling' => (string) ($this->option('risk-ceiling') ?: 'medium'),
                'duration_days' => (int) ($this->option('duration-days') ?: 7),
                'max_cycles' => (int) ($this->option('max-cycles') ?: 12),
                'max_merges' => (int) ($this->option('max-merges') ?: 10),
                'max_auto_merge_files' => (int) ($this->option('max-auto-merge-files') ?: 12),
                'allowed_providers' => $csv($this->option('allowed-providers')),
                'forbidden_actions' => $csv($this->option('forbidden-actions')),
                'quality_criteria' => $csv($this->option('quality-criteria')),
            ]);
            $this->emit($payload, function (array $p): void {
                $this->components->twoColumnDetail('AP-806 envelope arm', (string) ($p['status'] ?? ''));
                $this->line('  policy_hash: '.(string) ($p['policy_hash'] ?? ''));
                foreach ((array) ($p['blockers'] ?? []) as $b) {
                    $this->warn('  blocker: '.(string) $b);
                }
            });

            return (string) ($payload['status'] ?? '') === StewardshipAutonomyEnvelopeService::STATUS_ARMED ? self::SUCCESS : self::FAILURE;
        }

        $this->error('Unknown --envelope-action: '.$sub.' (use arm|show|disarm)');

        return self::FAILURE;
    }

    /**
     * Run a read-only validation command and return whether it succeeded.
     * Returns null (treated as "run the proof command") on any failure/exception.
     *
     * @param  array<string,mixed>  $args
     */
    private function runLoopCycleFirewall(LoopPreflightCycleFirewallService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
            'run_id' => (string) ($this->option('run-id') ?: ''),
            'cycle_index' => (int) $this->option('cycle-index'),
        ];

        $payload = $svc->evaluate($input);

        $this->emit($payload, function (array $p): void {
            $gates = is_array($p['gates'] ?? null) ? $p['gates'] : [];
            $candidate = is_array($p['candidate'] ?? null) ? $p['candidate'] : [];
            $this->components->twoColumnDetail('Loop Preflight Firewall', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Provider allowed', YesNo::trueFalse((bool) ($p['provider_allowed'] ?? false)));
            $this->components->twoColumnDetail('Block status', (string) ($p['block_status'] ?? 'null'));
            $this->components->twoColumnDetail('Merge target', (string) ($p['merge_target'] ?? ''));
            $this->components->twoColumnDetail('Candidate finding', (string) ($candidate['finding_id'] ?? ''));
            $this->components->twoColumnDetail('Candidate packet', (string) ($candidate['packet_id'] ?? ''));
            foreach (['environment', 'candidate_truth', 'packet_fitness', 'authority', 'cost'] as $gateName) {
                $gate = $gates[$gateName] ?? null;
                $passed = is_array($gate) ? (bool) ($gate['passed'] ?? false) : (bool) $gate;
                $this->components->twoColumnDetail('Gate '.$gateName, $passed ? 'passed' : 'blocked');
            }
            $this->components->twoColumnDetail('Blockers', (string) count((array) ($p['blockers'] ?? [])));
        });

        return in_array((string) ($payload['status'] ?? ''), ['block'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopAssuranceReport(LoopInvariantHarnessService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
            'run_id' => (string) ($this->option('run-id') ?: ''),
            'scenario' => (string) ($this->option('scenario') ?: ''),
            'strict' => (bool) $this->option('strict'),
        ];

        $payload = $svc->report($input);

        $this->emit($payload, function (array $p): void {
            $invariant = is_array($p['invariant_report'] ?? null) ? $p['invariant_report'] : [];
            $composed = is_array($p['composed'] ?? null) ? $p['composed'] : [];
            $this->components->twoColumnDetail('Status', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Invariant Report', (string) ($invariant['status'] ?? ''));
            $this->components->twoColumnDetail('Simulation', (string) data_get($composed, 'simulation.status', ''));
            $this->components->twoColumnDetail('Chaos', (string) data_get($composed, 'chaos.status', ''));
            $this->components->twoColumnDetail('Resource', (string) data_get($composed, 'resource.status', ''));
            $this->components->twoColumnDetail('Critical Violations', (string) ($invariant['critical_violations'] ?? 0));
            $this->components->twoColumnDetail('Blocks Long-Run', YesNo::format((bool) ($p['blocks_long_run_readiness'] ?? false)));
            $this->components->twoColumnDetail('Report Hash', (string) ($p['report_hash'] ?? ''));
        });

        return in_array((string) ($payload['status'] ?? ''), ['fail'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopAssuranceChaos(LoopChaosCertificationService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
            'profile' => (string) ($this->option('profile') ?: ''),
        ];

        $payload = $svc->certify($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('Status', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Profile', (string) ($p['profile'] ?? ''));
            $this->components->twoColumnDetail('Faults', (string) ($p['fault_count'] ?? 0));
            $this->components->twoColumnDetail('Handled safely', (string) ($p['handled_safely_count'] ?? 0));
            $this->components->twoColumnDetail('False success', (string) count((array) ($p['false_success'] ?? [])));
            $this->components->twoColumnDetail('Coverage complete', YesNo::format((bool) data_get($p, 'profile_coverage.complete', false)));
            $this->components->twoColumnDetail('Gates 24h', ((bool) ($p['gates_24h'] ?? false)) ? 'pass' : 'blocked');
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return in_array((string) ($payload['status'] ?? ''), ['fail'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopCycleQuality(CycleQualityScoreService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
            'run_id' => (string) ($this->option('run-id') ?: ''),
            'cycle_index' => (int) $this->option('cycle-index'),
        ];

        $payload = $svc->score($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('Status (band)', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Quality score (0..1)', (string) ($p['quality_score'] ?? 0));
            $this->components->twoColumnDetail('Quality floor', (string) ($p['floor'] ?? 0));
            $this->components->twoColumnDetail('Meets quality floor', YesNo::format((bool) ($p['meets_quality_floor'] ?? false)));
            $this->components->twoColumnDetail('Counts as leap (salto)', YesNo::format((bool) ($p['counts_as_leap'] ?? false)));
            $this->components->twoColumnDetail('Real productive merge', YesNo::format((bool) ($p['real_productive_merge'] ?? false)));
            $this->components->twoColumnDetail('Value summary', (string) ($p['value_summary'] ?? ''));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return in_array((string) ($payload['status'] ?? ''), ['low'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopLedgerArchive(LoopLedgerArchiveService $svc): int
    {
        $fixture = $this->jsonFixtureFromOption();
        $args = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $fixture,
        ];
        // Ledger consumers read segments/retain_raw_segments/disk_ceiling_bytes/
        // seal_failures/compact_index at the top level — spread the decoded
        // fixture keys (explicit args still win).
        if (is_array($fixture)) {
            $args = array_merge($fixture, $args);
        }

        $action = (string) ($this->option('block-action') ?: 'plan');
        $payload = match ($action) {
            'compact' => $svc->compact($args),
            'replay' => $svc->replayManifest($args),
            default => $svc->plan($args),
        };

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-14 Ledger Archive', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Action', (string) ($p['block_action'] ?? ''));
            $this->components->twoColumnDetail('Sealed segments', (string) ($p['sealed_segment_count'] ?? 0));
            $this->components->twoColumnDetail('Retained raw', implode(',', (array) ($p['retained_raw_segments'] ?? [])));
            $this->components->twoColumnDetail('Cycles indexed', (string) ($p['cycles_indexed'] ?? ($p['total_cycles'] ?? 0)));
            $this->components->twoColumnDetail('Replay', (((bool) ($p['replay_available'] ?? $p['replay_complete'] ?? false)) ? 'available' : 'paused'));
            $this->components->twoColumnDetail('Next action', (string) ($p['next_action'] ?? ''));
            $this->components->twoColumnDetail('Blockers', (string) count((array) ($p['blockers'] ?? [])));
        });

        return in_array((string) ($payload['status'] ?? ''), ['paused'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopEnterpriseBlock(LongHorizonLoopDeliveryLedgerService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
            'block_action' => (string) ($this->option('block-action') ?: ''),
        ];

        $payload = $svc->baseline($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-00 Enterprise Block', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Block action', (string) ($p['block_action'] ?? ''));
            $this->components->twoColumnDetail('Schema', (string) ($p['schema_version'] ?? ''));
            $this->components->twoColumnDetail('Blockers', (string) count((array) ($p['blockers'] ?? [])));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return in_array((string) ($payload['status'] ?? ''), ['blocked'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopPostCycleAudit(LoopPostCycleAuditorService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
            'run_id' => (string) ($this->option('run-id') ?: ''),
            'cycle_index' => (int) $this->option('cycle-index'),
        ];

        $payload = $svc->audit($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-02 Post-Cycle Audit', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Run', (string) ($p['run_id'] ?? ''));
            $this->components->twoColumnDetail('Cycle index', (string) ($p['cycle_index'] ?? ''));
            $this->components->twoColumnDetail('Critical violations', (string) ($p['critical_violations'] ?? 0));
            $this->components->twoColumnDetail('Violations', (string) count((array) ($p['violations'] ?? [])));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return in_array((string) ($payload['status'] ?? ''), ['invalid_cycle', 'critical_violation'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopFlightRecorder(LoopFlightRecorderService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
            'run_id' => (string) ($this->option('run-id') ?: ''),
            'cycle_index' => (int) $this->option('cycle-index'),
        ];

        $payload = $svc->explain($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-03 Flight Recorder', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Run', (string) ($p['run_id'] ?? ''));
            $this->components->twoColumnDetail('Cycle index', (string) ($p['cycle_index'] ?? ''));
            $this->components->twoColumnDetail('Stages', (string) count((array) ($p['stages'] ?? [])));
            $this->components->twoColumnDetail('Blockers', (string) count((array) ($p['blockers'] ?? [])));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return in_array((string) ($payload['status'] ?? ''), ['incomplete'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopAssuranceSimulate(LoopDeterministicSimulatorService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
            'cycles' => (int) $this->option('cycles'),
        ];

        $payload = $svc->simulate($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-04 Deterministic Simulator', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Cycles', (string) ($p['cycles'] ?? ($p['cycle_count'] ?? 0)));
            $this->components->twoColumnDetail('Merges', (string) ($p['merges'] ?? 0));
            $this->components->twoColumnDetail('Blocked', (string) ($p['blocked'] ?? 0));
            $this->components->twoColumnDetail('Blockers', (string) count((array) ($p['blockers'] ?? [])));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return in_array((string) ($payload['status'] ?? ''), ['fail'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopCycleState(TransactionalCycleStateService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
            'run_id' => (string) ($this->option('run-id') ?: ''),
            'cycle_index' => (int) $this->option('cycle-index'),
            'scenario' => (string) ($this->option('scenario') ?: ''),
        ];

        $payload = $svc->load($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-07 Transactional Cycle State', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Run', (string) ($p['run_id'] ?? ''));
            $this->components->twoColumnDetail('Cycle index', (string) ($p['cycle_index'] ?? ''));
            $this->components->twoColumnDetail('Scenario', (string) ($p['scenario'] ?? ''));
            $this->components->twoColumnDetail('Blockers', (string) count((array) ($p['blockers'] ?? [])));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return in_array((string) ($payload['status'] ?? ''), ['fail_closed'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopResourceGovernor(LoopResourceGovernorService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
        ];

        $payload = $svc->evaluate($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-08 Resource Governor', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Action', (string) ($p['action'] ?? ($p['decision'] ?? '')));
            $this->components->twoColumnDetail('Disk', (string) ($p['disk_status'] ?? ''));
            $this->components->twoColumnDetail('Memory', (string) ($p['memory_status'] ?? ''));
            $this->components->twoColumnDetail('Blockers', (string) count((array) ($p['blockers'] ?? [])));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return in_array((string) ($payload['status'] ?? ''), ['stop'], true) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Compose the LIVE canonical backlog (AP-806) into Backlog Depth Governor seams,
     * provider-free: the canonical high-value parents and the bounded Self-Construction
     * packets they decompose into via the admission bridge. The depth governor reads
     * these via its input seam (per its contract it never instantiates the backlog).
     * This is the SAME source the loop's selectCandidate consumes, so the reported
     * depth reflects real selectable runway, not an empty diagnostic default.
     *
     * @return array{canonical_parents: list<array<string,mixed>>, self_construction_packets: list<array<string,mixed>>}
     */
    private function runLoopBacklogDepth(BacklogDepthGovernorService $svc): int
    {
        $area = (string) $this->option('area');
        $focus = (string) $this->option('focus');
        $fixture = $this->jsonFixtureFromOption();
        $input = [
            'area' => $area,
            'focus' => $focus,
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $fixture,
            'strict' => (bool) $this->option('strict'),
        ];

        // Default to the LIVE canonical backlog (the source selectCandidate uses); an
        // explicit --fixture-file lets the operator override for what-if analysis.
        if ($fixture === null) {
            $live = $this->composeLiveCanonicalBacklog($area, $focus);
            $input['canonical_parents'] = $live['canonical_parents'];
            $input['self_construction_packets'] = $live['self_construction_packets'];
        }

        $payload = $svc->assess($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-09 Backlog Depth Governor', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Depth', (string) ($p['depth'] ?? ($p['backlog_depth'] ?? 0)));
            $this->components->twoColumnDetail('Floor', (string) ($p['floor'] ?? 0));
            $this->components->twoColumnDetail('Meets floor', YesNo::format((bool) ($p['meets_floor'] ?? false)));
            $this->components->twoColumnDetail('Blockers', (string) count((array) ($p['blockers'] ?? [])));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return in_array((string) ($payload['status'] ?? ''), ['below_floor'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopBacklogRegenerate(BacklogRegenerationEngineService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
        ];

        $payload = $svc->regenerate($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-10 Backlog Regeneration', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Generated', (string) ($p['generated_count'] ?? count((array) ($p['candidates'] ?? []))));
            $this->components->twoColumnDetail('Sources', (string) count((array) ($p['sources'] ?? [])));
            $this->components->twoColumnDetail('Blockers', (string) count((array) ($p['blockers'] ?? [])));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return in_array((string) ($payload['status'] ?? ''), ['empty'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopQualityDrift(LoopQualityDriftDetectorService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
        ];

        $payload = $svc->detect($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-12 Quality Drift Detector', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Drift detected', YesNo::format((bool) ($p['drift_detected'] ?? false)));
            $this->components->twoColumnDetail('Window', (string) ($p['window'] ?? ($p['window_size'] ?? 0)));
            $this->components->twoColumnDetail('Trend', (string) ($p['trend'] ?? ''));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function runLoopSupervisor(AlwaysOnLoopSupervisorService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
        ];

        $payload = $svc->assess($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-13 Always-On Supervisor', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Liveness', (string) ($p['liveness'] ?? ($p['liveness_status'] ?? '')));
            $this->components->twoColumnDetail('Stalled', YesNo::format((bool) ($p['stalled'] ?? false)));
            $this->components->twoColumnDetail('Restart needed', YesNo::format((bool) ($p['restart_needed'] ?? false)));
            $this->components->twoColumnDetail('Blockers', (string) count((array) ($p['blockers'] ?? [])));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return in_array((string) ($payload['status'] ?? ''), ['blocked'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopMaintenanceWindow(SelfHealingMaintenanceWindowService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
            'profile' => (string) ($this->option('profile') ?: ''),
        ];

        $payload = $svc->run($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-15 Maintenance Window', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Profile', (string) ($p['profile'] ?? ''));
            $this->components->twoColumnDetail('Tasks', (string) count((array) ($p['tasks'] ?? [])));
            $this->components->twoColumnDetail('Healed', (string) ($p['healed_count'] ?? 0));
            $this->components->twoColumnDetail('Blockers', (string) count((array) ($p['blockers'] ?? [])));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return in_array((string) ($payload['status'] ?? ''), ['blocked'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopProviderReliability(ProviderReliabilityLayerService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
        ];

        $payload = $svc->assess($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-16 Provider Reliability', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Providers', (string) count((array) ($p['providers'] ?? [])));
            $this->components->twoColumnDetail('Degraded', (string) ($p['degraded_count'] ?? 0));
            $this->components->twoColumnDetail('Circuit open', (string) ($p['circuit_open_count'] ?? 0));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function runLoopDisasterRecoveryPreflight(LoopDisasterRecoveryService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
            'scenario' => (string) ($this->option('scenario') ?: ''),
        ];

        $payload = $svc->preflight($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-17 Disaster Recovery Preflight', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Scenario', (string) ($p['scenario'] ?? ''));
            $this->components->twoColumnDetail('Checks', (string) count((array) ($p['checks'] ?? [])));
            $this->components->twoColumnDetail('Recoverable', YesNo::format((bool) ($p['recoverable'] ?? false)));
            $this->components->twoColumnDetail('Blockers', (string) count((array) ($p['blockers'] ?? [])));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return in_array((string) ($payload['status'] ?? ''), ['fail_closed'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function runLoopIsolationStatus(LoopProcessIsolationStatusService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
            'horizon' => (string) ($this->option('horizon') ?: ''),
        ];

        $payload = $svc->status($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-18 Process Isolation Status', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Horizon', (string) ($p['horizon'] ?? ''));
            $this->components->twoColumnDetail('Isolated', YesNo::format((bool) ($p['isolated'] ?? false)));
            $this->components->twoColumnDetail('Locks', (string) count((array) ($p['locks'] ?? [])));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function runLoopCertificationLadder(LongRunCertificationLadderService $svc): int
    {
        $input = [
            'area' => (string) $this->option('area'),
            'focus' => (string) $this->option('focus'),
            'repo_root' => (string) ($this->option('repo-root') ?: ''),
            'fixture' => $this->jsonFixtureFromOption(),
            'horizon' => (string) ($this->option('horizon') ?: ''),
        ];

        $payload = $svc->evaluate($input);

        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('LHL-19 Certification Ladder', (string) ($p['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Horizon', (string) ($p['horizon'] ?? ''));
            $this->components->twoColumnDetail('Highest passed', (string) ($p['highest_passed_rung'] ?? ($p['highest_passed'] ?? '')));
            $this->components->twoColumnDetail('Rungs', (string) count((array) ($p['rungs'] ?? [])));
            $this->components->twoColumnDetail('Blockers', (string) count((array) ($p['blockers'] ?? [])));
            $this->components->twoColumnDetail('Report hash', (string) ($p['report_hash'] ?? ''));
        });

        return in_array((string) ($payload['status'] ?? ''), ['blocked'], true) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * AP-810 shared fixture reader for the loop assurance/firewall/quality/ledger
     * actions. Reads the --fixture-file option (JSON object/array or JSONL).
     * Returns null when the option is empty or the file is unreadable/invalid.
     *
     * @return array<mixed>|null
     */
}

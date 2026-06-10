<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship;

use App\Console\Commands\AtlasSoftwareCompanyStewardshipCommand;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchSystemCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeCockpitSurfaceService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalControlReceiptService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalControlsReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-777 · Continuous Stewardship 24h Readiness Gate.
 *
 * Read-only start gate for the first full-day Atlas Continuous Stewardship Loop.
 * It proves the control plane is ready before an external scheduler/launchd/cron
 * caller is allowed to run the AP-766 runner for 24 hours.
 */
final class ContinuousStewardshipDayReadinessService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.continuous_24h_readiness.v1';

    public const STATUS_READY = 'ready_for_24h_run';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    public function __construct(
        private readonly ContinuousStewardshipRunnerService $runner,
        private readonly StewardshipBranchSystemCertificationService $branchSystemCertification,
    ) {}

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->runner->setStorageRootForTesting($dir !== null ? $dir.'/continuous_runner' : null);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function assess(array $input = []): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));
        $repoRoot = $this->repoRoot($input);
        $durationHours = max(1, (int) ($input['duration_hours'] ?? 24));
        $maxRunsPerDay = max(0, (int) ($input['max_runs_per_day'] ?? ContinuousStewardshipRunnerService::DEFAULT_MAX_RUNS_PER_DAY));
        $minIntervalSeconds = max(0, (int) ($input['min_interval_seconds'] ?? ContinuousStewardshipRunnerService::DEFAULT_MIN_INTERVAL_SECONDS));
        $lockTtlSeconds = max(30, (int) ($input['lock_ttl_seconds'] ?? ContinuousStewardshipRunnerService::DEFAULT_LOCK_TTL_SECONDS));
        $enabled = (bool) ($input['enabled'] ?? $input['enable_continuous_runner'] ?? false);

        $runnerStatus = $this->runner->status([
            'area_id' => $areaId,
            'enabled' => $enabled,
            'global_kill_switch' => (bool) ($input['global_kill_switch'] ?? $input['kill_switch'] ?? false),
            'area_kill_switch' => (bool) ($input['area_kill_switch'] ?? false),
            'pause_until' => (string) ($input['pause_until'] ?? ''),
            'min_interval_seconds' => $minIntervalSeconds,
            'lock_ttl_seconds' => $lockTtlSeconds,
            'max_runs_per_day' => $maxRunsPerDay,
        ]);
        $branchCert = $this->branchSystemCertification->certify([
            'area_id' => $areaId,
            'repo_root' => $repoRoot,
        ]);
        $surfaceCoverage = $this->surfaceCoverage($repoRoot);
        $commandCoverage = $this->commandCoverage($repoRoot);
        $policy = [
            'duration_hours' => $durationHours,
            'continuous_runner_enabled' => $enabled,
            'max_runs_per_day' => $maxRunsPerDay,
            'min_interval_seconds' => $minIntervalSeconds,
            'lock_ttl_seconds' => $lockTtlSeconds,
            'global_kill_switch_active' => (bool) ($input['global_kill_switch'] ?? $input['kill_switch'] ?? false),
            'area_kill_switch_active' => (bool) ($input['area_kill_switch'] ?? false),
            'requires_external_scheduler' => true,
            'installs_scheduler' => false,
            'first_day_mode' => 'operator_observed_sandboxed_stewardship',
        ];

        $blockers = $this->blockers($policy, $runnerStatus, $branchCert, $surfaceCoverage, $commandCoverage);
        $status = $blockers === [] ? self::STATUS_READY : self::STATUS_BLOCKED;

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-777',
            'status' => $status,
            'area_id' => $areaId,
            'focus' => (string) ($input['focus'] ?? 'dev_forge'),
            'stack' => 'Atlas Software Company Stewardship Stack',
            'layer' => 'Atlas Continuous Stewardship Loop',
            'source_ap_contracts' => ['AP-745', 'AP-746', 'AP-754', 'AP-755', 'AP-765', 'AP-766', 'AP-776', 'AP-777'],
            'repo' => [
                'repo_root' => $repoRoot,
                'repo_root_hash' => hash('sha256', $repoRoot),
            ],
            'policy' => $policy,
            'runner_status' => $runnerStatus,
            'branch_system_certification' => $branchCert,
            'surface_coverage' => $surfaceCoverage,
            'command_coverage' => $commandCoverage,
            'operator_start_command' => $this->operatorStartCommand($areaId, $maxRunsPerDay, $minIntervalSeconds, $lockTtlSeconds),
            'blockers' => $blockers,
            'next_actions' => $this->nextActions($status, $blockers),
            'claim_policy' => [
                'read_only' => true,
                'provider_invoked' => false,
                'branch_created' => false,
                'worktree_created' => false,
                'merge_performed' => false,
                'scheduler_installed' => false,
                'starts_loop' => false,
                'certifies_start_gate_only' => true,
            ],
            'generated_at' => $this->now(),
        ];
        $payload['readiness_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($payload));

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function surfaceCoverage(string $repoRoot): array
    {
        $classes = [
            'product_mode_cockpit' => ProductModeCockpitSurfaceService::class,
            'product_mode_controls' => ProductModeOperationalControlsReadModelService::class,
            'product_mode_control_receipts' => ProductModeOperationalControlReceiptService::class,
            'runtime_result_bridge' => StewardshipRuntimeResultBridgeService::class,
        ];
        $coverage = [];
        foreach ($classes as $id => $class) {
            $coverage[$id] = class_exists($class);
        }

        $files = [
            'docs/ap/AP-754-product-mode-operational-controls-read-model-contract.md',
            'docs/ap/AP-755-product-mode-operational-control-receipts-contract.md',
            'docs/ap/AP-765-stewardship-runtime-result-bridge-contract.md',
            'tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceServiceTest.php',
            'tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlsReadModelServiceTest.php',
            'tests/Unit/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptServiceTest.php',
        ];
        $fileCoverage = [];
        foreach ($files as $file) {
            $fileCoverage[$file] = is_file($repoRoot.DIRECTORY_SEPARATOR.$file);
        }

        return [
            'status' => ! in_array(false, $coverage, true) && ! in_array(false, $fileCoverage, true) ? 'ready' : 'blocked',
            'class_coverage' => $coverage,
            'file_coverage' => $fileCoverage,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function commandCoverage(string $repoRoot): array
    {
        $commandPath = $repoRoot.'/app/Console/Commands/AtlasSoftwareCompanyStewardshipCommand.php';
        $source = is_file($commandPath) ? (string) file_get_contents($commandPath) : '';
        $actions = [
            'continuous-24h-readiness',
            'continuous-runner',
            'continuous-runner-status',
            'branch-system-certify',
            'product-mode-cockpit',
            'product-mode-controls',
            'product-mode-control-receipt',
            'runtime-result-bridge',
        ];
        $coverage = [];
        foreach ($actions as $action) {
            $coverage[$action] = str_contains($source, $action);
        }

        return [
            'command_class' => AtlasSoftwareCompanyStewardshipCommand::class,
            'command_file' => $commandPath,
            'coverage' => $coverage,
            'status' => is_file($commandPath) && ! in_array(false, $coverage, true) ? 'ready' : 'blocked',
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $runnerStatus
     * @param  array<string,mixed>  $branchCert
     * @param  array<string,mixed>  $surfaceCoverage
     * @param  array<string,mixed>  $commandCoverage
     * @return list<string>
     */
    private function blockers(array $policy, array $runnerStatus, array $branchCert, array $surfaceCoverage, array $commandCoverage): array
    {
        $blockers = [];
        if ((int) $policy['duration_hours'] < 24) {
            $blockers[] = 'duration_less_than_24h';
        }
        if (! (bool) $policy['continuous_runner_enabled']) {
            $blockers[] = 'continuous_runner_not_enabled';
        }
        if ((bool) $policy['global_kill_switch_active']) {
            $blockers[] = 'global_kill_switch_active';
        }
        if ((bool) $policy['area_kill_switch_active']) {
            $blockers[] = 'area_kill_switch_active';
        }
        if ((int) $policy['max_runs_per_day'] < 1) {
            $blockers[] = 'daily_budget_must_allow_at_least_one_run';
        }
        if ((int) $policy['min_interval_seconds'] < 60) {
            $blockers[] = 'min_interval_too_low_for_24h_run';
        }
        if (($runnerStatus['status'] ?? '') !== 'ready') {
            $blockers[] = 'continuous_runner_status_not_ready';
        }
        foreach ((array) ($runnerStatus['blockers'] ?? []) as $blocker) {
            $blockers[] = 'runner:'.(string) $blocker;
        }
        if (($branchCert['status'] ?? '') !== StewardshipBranchSystemCertificationService::STATUS_CERTIFIED) {
            $blockers[] = 'branch_system_not_certified';
        }
        if (($surfaceCoverage['status'] ?? '') !== 'ready') {
            $blockers[] = 'product_mode_or_result_surface_not_ready';
        }
        if (($commandCoverage['status'] ?? '') !== 'ready') {
            $blockers[] = 'required_cli_actions_missing';
        }

        return StewardshipStringListNormalizer::uniqueStrings($blockers);
    }

    private function operatorStartCommand(string $areaId, int $maxRunsPerDay, int $minIntervalSeconds, int $lockTtlSeconds): string
    {
        return 'php artisan atlas:software-company-stewardship continuous-runner'
            .' --area='.$areaId
            .' --focus=dev_forge'
            .' --mode=execute'
            .' --enable-continuous-runner'
            .' --record-runner-run'
            .' --max-runs-per-day='.$maxRunsPerDay
            .' --min-interval-seconds='.$minIntervalSeconds
            .' --runner-lock-ttl-seconds='.$lockTtlSeconds
            .' --json';
    }

    /**
     * @param  list<string>  $blockers
     * @return list<string>
     */
    private function nextActions(string $status, array $blockers): array
    {
        if ($status === self::STATUS_READY) {
            return [
                'Start the external scheduler/launchd/cron for one day using operator_start_command.',
                'Watch Product Mode and Morning Inbox for findings, receipts, branch review items and blocked gates.',
                'Keep AP-776 branch certification green before enabling merge queue execution.',
            ];
        }

        return [
            'Do not start a 24h runner yet.',
            'Resolve blockers first: '.implode(', ', $blockers),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function repoRoot(array $input): string
    {
        $root = trim((string) ($input['repo_root'] ?? ''));
        if ($root !== '') {
            return rtrim($root, DIRECTORY_SEPARATOR);
        }

        return function_exists('base_path') ? base_path() : getcwd();
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_\-]+/', '_', $slug) ?: 'default';

        return trim($slug, '_') ?: 'default';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function identity(array $payload): array
    {
        unset($payload['generated_at'], $payload['readiness_hash']);

        return $payload;
    }
}

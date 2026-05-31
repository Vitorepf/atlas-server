<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipIntegrationLanePromotionService;
use Illuminate\Console\Command;

final class AtlasSoftwareCompanyIntegrationLanePromoteCommand extends Command
{
    protected $signature = 'atlas:software-company-stewardship:integration-lane-promote
        {--lane-ref= : Integration lane ref under atlas/integration/*}
        {--base-ref=main : Base ref to fast-forward (e.g. main)}
        {--area=agentic_engineering_os : Stewardship area id}
        {--repo-root= : Git repository root; defaults to the app base path}
        {--lease-owner= : AP-775 lease owner; defaults to integration_lane_promotion_<area>}
        {--allow-code-auto-merge : AP-783 permits AP-769 code auto-merge only with green validation}
        {--max-auto-merge-files=12 : AP-783/AP-769 maximum changed files for auto-merge eligibility}
        {--run-validation : AP-783 asks AP-769 to run --test-command validations before promotion}
        {--test-command=* : AP-783/AP-769 validation command, repeatable}
        {--worktree-path= : Worktree used for validation when the lane contains newly created classes}
        {--record : Persist AP-783 promotion receipt}
        {--json : Emit JSON only}';

    protected $description = 'AP-783 · promote a clean integration lane into the base ref via AP-775 + AP-769 ff-only merge.';

    public function handle(StewardshipIntegrationLanePromotionService $service): int
    {
        $payload = $service->promote([
            'area_id' => (string) $this->option('area'),
            'repo_root' => (string) ($this->option('repo-root') ?: base_path()),
            'base_ref' => (string) ($this->option('base-ref') ?: 'main'),
            'lane_ref' => (string) ($this->option('lane-ref') ?: ''),
            'lease_owner' => (string) ($this->option('lease-owner') ?: ''),
            'allow_code_auto_merge' => (bool) $this->option('allow-code-auto-merge'),
            'max_auto_merge_files' => (int) ($this->option('max-auto-merge-files') ?: 12),
            'run_validation' => (bool) $this->option('run-validation'),
            'test_commands' => array_values(array_filter((array) $this->option('test-command'), 'is_string')),
            'worktree_path' => (string) ($this->option('worktree-path') ?: ''),
            'record' => (bool) $this->option('record'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? '') === StewardshipIntegrationLanePromotionService::STATUS_BLOCKED
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->components->twoColumnDetail('AP-783 integration lane promotion', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Promotion', (string) ($payload['promotion_id'] ?? ''));
        $this->components->twoColumnDetail('Lane', (string) ($payload['lane_ref'] ?? ''));
        $this->components->twoColumnDetail('Base', (string) ($payload['base_ref'] ?? ''));
        $this->components->twoColumnDetail('Promoted', ((bool) ($payload['promoted'] ?? false)) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Lease', (string) ($payload['lease_status'] ?? ''));
        $this->components->twoColumnDetail('Governance', (string) ($payload['governance_status'] ?? ''));

        foreach ((array) ($payload['blockers'] ?? []) as $blocker) {
            $this->warn('  blocker: '.(string) $blocker);
        }

        return ($payload['status'] ?? '') === StewardshipIntegrationLanePromotionService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }
}

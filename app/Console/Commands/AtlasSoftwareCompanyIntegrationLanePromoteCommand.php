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

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipIntegrationLaneService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasSoftwareCompanyIntegrationLaneCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:software-company-stewardship:integration-lane
        {--area=agentic_engineering_os : Stewardship area id}
        {--repo-root= : Git repository root; defaults to the app base path}
        {--base-ref=main : Base branch/ref}
        {--branch-ref= : Candidate branch ref}
        {--lane-ref= : Optional integration lane ref}
        {--record : Persist AP-782/AP-769 receipts}
        {--json : Emit JSON only}';

    protected $description = 'AP-782 · advance a safe visible integration lane without mutating main.';

    public function handle(StewardshipIntegrationLaneService $service): int
    {
        $payload = $service->integrate([
            'area_id' => (string) $this->option('area'),
            'repo_root' => (string) ($this->option('repo-root') ?: base_path()),
            'base_ref' => (string) ($this->option('base-ref') ?: 'main'),
            'branch_ref' => (string) ($this->option('branch-ref') ?: ''),
            'lane_ref' => (string) ($this->option('lane-ref') ?: ''),
            'record' => (bool) $this->option('record'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return ($payload['status'] ?? '') === StewardshipIntegrationLaneService::STATUS_BLOCKED
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->components->twoColumnDetail('AP-782 integration lane', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Integration', (string) ($payload['integration_id'] ?? ''));
        $this->components->twoColumnDetail('Candidate', (string) data_get($payload, 'candidate.branch_ref', ''));
        $this->components->twoColumnDetail('Lane', (string) data_get($payload, 'integration_lane.lane_ref', ''));
        $this->components->twoColumnDetail('Lane commit', (string) data_get($payload, 'integration_lane.lane_commit_after', ''));
        $this->components->twoColumnDetail('Base untouched', YesNo::format((bool) data_get($payload, 'repo.base_untouched', false)));
        $this->components->twoColumnDetail('Review packet', (string) data_get($payload, 'branch_review_packet.status', ''));

        foreach ((array) ($payload['blockers'] ?? []) as $blocker) {
            $this->warn('  blocker: '.(string) $blocker);
        }
        foreach ((array) ($payload['next_operator_action'] ?? []) as $action) {
            $this->line('  next: '.(string) $action);
        }

        return ($payload['status'] ?? '') === StewardshipIntegrationLaneService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }
}

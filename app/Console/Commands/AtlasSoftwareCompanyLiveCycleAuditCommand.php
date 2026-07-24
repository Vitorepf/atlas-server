<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipLiveCycleAuditService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasSoftwareCompanyLiveCycleAuditCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:software-company-stewardship:live-cycle-audit
        {--area=agentic_engineering_os : Stewardship area id}
        {--base-ref=main : Base branch/ref inspected for promotion readiness}
        {--repo-root= : Git repository root; defaults to the app base path}
        {--json : Emit JSON only}';

    protected $description = 'AP-784 · read-only audit of real stewardship git branches, integration lanes and promotion readiness.';

    public function handle(StewardshipLiveCycleAuditService $service): int
    {
        $payload = $service->audit([
            'area_id' => (string) $this->option('area'),
            'base_ref' => (string) ($this->option('base-ref') ?: 'main'),
            'repo_root' => (string) ($this->option('repo-root') ?: base_path()),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $this->exitCode($payload);
        }

        $this->components->twoColumnDetail('AP-784 live cycle audit', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Area', (string) ($payload['area_id'] ?? ''));
        $this->components->twoColumnDetail('Base ref', (string) ($payload['base_ref'] ?? ''));
        $this->components->twoColumnDetail('Base clean', YesNo::format((bool) ($payload['base_clean'] ?? false)));
        $this->components->twoColumnDetail('Main commit', (string) ($payload['main_commit'] ?? ''));
        $this->components->twoColumnDetail('Latest lane commit', (string) ($payload['latest_lane_commit'] ?? ''));
        $this->components->twoColumnDetail('Promotion ready', YesNo::format((bool) ($payload['promotion_ready'] ?? false)));
        $this->components->twoColumnDetail('Proof branches', (string) count((array) ($payload['proof_branches'] ?? [])));
        $this->components->twoColumnDetail('Integration lanes', (string) count((array) ($payload['integration_lanes'] ?? [])));

        foreach ((array) ($payload['real_steps'] ?? []) as $step => $real) {
            $this->components->twoColumnDetail('real: '.$step, YesNo::format($real));
        }

        foreach ((array) ($payload['blockers'] ?? []) as $blocker) {
            $this->warn('  blocker: '.(string) $blocker);
        }

        $this->line('  truth: '.(string) ($payload['operator_truth'] ?? ''));
        $this->line('  next: '.(string) ($payload['next_real_action'] ?? ''));

        return $this->exitCode($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(array $payload): int
    {
        return match ($payload['status'] ?? '') {
            StewardshipLiveCycleAuditService::STATUS_FAILED => self::FAILURE,
            StewardshipLiveCycleAuditService::STATUS_BLOCKED => self::FAILURE,
            default => self::SUCCESS,
        };
    }
}

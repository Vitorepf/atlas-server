<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopOperationalOrchestratorService;
use Illuminate\Console\Command;

/**
 * Night Shift · Area Focus Loop · Operational Orchestrator CLI (AP-722).
 *
 * Atlas Software Company Stewardship Stack é stack/capability family dentro do
 * Atlas Autonomous Software Company Runtime, não OS novo. Runs the read-only
 * end-to-end operational cycle (core → scan → inbox → work orders → evidence →
 * certification) for a canonical area. It executes no fix, opens no branch,
 * dispatches no work and never merges/deploys/touches secrets.
 */
class AtlasNightShiftAreaFocusOperateCommand extends Command
{
    protected $signature = 'atlas:night-shift:area-focus-operate
        {--area=agentic_engineering_os : Canonical area_id}
        {--hours=24 : Scan/gap window forwarded to the owners}
        {--limit= : Cap the number of findings}
        {--json : Emit JSON}';

    protected $description = 'Atlas Night Shift · Area Focus Loop operational orchestrator + certification (read-only; composes AP-716..AP-720; no execution, no branch, no dispatch, no merge/deploy/secrets).';

    public function handle(AreaFocusLoopOperationalOrchestratorService $orchestrator): int
    {
        $input = ['area_id' => (string) $this->option('area'), 'hours' => (int) $this->option('hours')];
        if (($limit = $this->option('limit')) !== null && $limit !== '') {
            $input['limit'] = (int) $limit;
        }

        $report = $orchestrator->run($input);

        $this->emit($report, function (array $p): void {
            $this->components->twoColumnDetail('Area Focus Loop', 'operational cycle (read-only) · AP-722');
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? '?'));
            $this->components->twoColumnDetail('Status', (string) ($p['status'] ?? 'unknown'));
            if (($p['status'] ?? '') === AreaFocusLoopOperationalOrchestratorService::STATUS_BLOCKED) {
                $this->warn('  blocked: '.(string) ($p['reason'] ?? '?').' · '.(string) ($p['detail'] ?? ''));

                return;
            }
            $cert = $p['operational_certification'] ?? [];
            $this->components->twoColumnDetail('Certification', (string) ($cert['status'] ?? '?').' (operational='.(($cert['operational'] ?? false) ? 'yes' : 'no').')');
            foreach (($p['stage_status'] ?? []) as $stage => $st) {
                $this->components->twoColumnDetail('  stage · '.$stage, (string) $st);
            }
            $counts = $p['counts'] ?? [];
            $this->components->twoColumnDetail('Findings / WO / Inbox', sprintf(
                '%d / %d (emitted %d, blocked %d) / %d',
                (int) ($counts['finding_count'] ?? 0),
                (int) ($counts['work_order_count'] ?? 0),
                (int) ($counts['emitted_work_orders'] ?? 0),
                (int) ($counts['blocked_work_orders'] ?? 0),
                (int) ($counts['inbox_item_count'] ?? 0),
            ));
            $this->components->twoColumnDetail('Morning inbox ready', ($p['morning_inbox_ready'] ?? false) ? 'yes' : 'no');
            foreach (($cert['failing_checks'] ?? []) as $fail) {
                $this->warn('  failing check: '.(string) $fail);
            }
        });

        return ($report['status'] ?? '') === AreaFocusLoopOperationalOrchestratorService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $human($payload);
    }
}

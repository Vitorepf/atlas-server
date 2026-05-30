<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanExecutionOrchestratorService;
use Illuminate\Console\Command;

/**
 * Pilar 1 · Plan Execution · E2E entry.
 *
 * Single end-to-end pass: decompose -> track (rollup) -> certify, via the
 * orchestrator. Honest status floor: blocked decomposition or blocked
 * certification surfaces as blocked; complete ONLY when the cert verdict is
 * complete; partial otherwise. The only side effect is the tracker's
 * append-only JSONL. No provider, no branch, no git, no merge, no deploy.
 */
class AtlasPlanExecutionE2eCommand extends Command
{
    protected $signature = 'atlas:plan-execution:e2e
        {--doc= : Path to the build-plan markdown document}
        {--area=agentic_engineering_os : Canonical area_id}
        {--scope-profile= : Slice planner scope profile (balanced|factory_max)}
        {--session=* : AP-786 session_id(s) for real-cycle certification}
        {--integration-green : Mark the integration check green}
        {--json : Emit JSON}';

    protected $description = 'Atlas Plan Execution (Pilar 1) · full E2E pass decompose->track->certify. Real-or-blocked; no provider, no merge, no deploy, no secrets.';

    public function handle(PlanExecutionOrchestratorService $orchestrator): int
    {
        $doc = trim((string) $this->option('doc'));
        if ($doc === '') {
            $this->error('--doc=<path> is required.');

            return self::FAILURE;
        }

        $result = $orchestrator->execute([
            'doc_path' => $doc,
            'area_id' => (string) $this->option('area'),
            'scope_profile' => (string) $this->option('scope-profile'),
            'session_ids' => $this->sessionIds(),
            'integration_check' => ['green' => (bool) $this->option('integration-green')],
        ]);

        $status = (string) ($result['orchestration_status'] ?? '');

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Plan Execution', 'e2e');
            $this->components->twoColumnDetail('Plan', (string) ($result['plan_id'] ?? '?'));
            $this->components->twoColumnDetail('Status', strtoupper($status !== '' ? $status : 'unknown'));
            $this->components->twoColumnDetail('Stage reached', (string) ($result['stage_reached'] ?? '?'));
            foreach ((array) ($result['blockers'] ?? []) as $blocker) {
                $this->line('  blocker: '.(string) $blocker);
            }
        }

        return $status === PlanExecutionOrchestratorService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function sessionIds(): array
    {
        return array_values(array_filter(array_map(
            static fn ($v): string => is_string($v) ? trim($v) : '',
            (array) $this->option('session'),
        ), static fn (string $v): bool => $v !== ''));
    }
}

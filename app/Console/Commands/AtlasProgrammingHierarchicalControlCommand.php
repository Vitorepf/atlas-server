<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use App\Services\Ai\Programming\Governance\ProgrammingHierarchicalControlLoopService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Print the Atlas Hierarchical Control Loop decision for a work item.
 *
 * @see docs/engineering-knowledge-base/atlas-hierarchical-control-loop.md
 */
class AtlasProgrammingHierarchicalControlCommand extends Command
{
    protected $signature = 'atlas:programming:hierarchical-control
        {work_item : Work item code or UUID}
        {--strict : Exit non-zero unless the halt decision is submit}
        {--json : Print machine-readable JSON}';

    protected $description = 'Evaluate the H/L control state and halt decision for a programming work item.';

    public function handle(
        ProgrammingGovernanceService $governance,
        ProgrammingHierarchicalControlLoopService $controller,
    ): int {
        try {
            $workItem = $governance->find((string) $this->argument('work_item'));
            $payload = $controller->evaluate($workItem);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $this->resolveExit($payload, (bool) $this->option('strict'));
        }

        $decision = (array) ($payload['halt_decision'] ?? []);
        $score = (array) ($payload['readiness_score'] ?? []);

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Hierarchical Control Loop</>', (string) $payload['work_item_code']);
        $this->components->twoColumnDetail('Action', (string) ($decision['action'] ?? '-'));
        $this->components->twoColumnDetail('Reason', (string) ($decision['reason'] ?? '-'));
        $this->components->twoColumnDetail('Readiness', (string) ($score['score'] ?? '-').'/9.5');
        $this->components->twoColumnDetail('Next step', (string) ($decision['next_step'] ?? '-'));
        $this->components->twoColumnDetail('Next command', (string) ($decision['next_command'] ?? '-'));

        return $this->resolveExit($payload, (bool) $this->option('strict'));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function resolveExit(array $payload, bool $strict): int
    {
        if (! $strict) {
            return self::SUCCESS;
        }

        return data_get($payload, 'halt_decision.action') === ProgrammingHierarchicalControlLoopService::ACTION_SUBMIT
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}

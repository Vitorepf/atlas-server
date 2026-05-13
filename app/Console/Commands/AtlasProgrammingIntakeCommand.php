<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Create a trackable Programming Governance work item from an intent.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
 */
class AtlasProgrammingIntakeCommand extends Command
{
    protected $signature = 'atlas:programming:intake
        {intent : Plain-text description of the work to govern}
        {--type= : Override classifier intent_type (feature, bugfix, refactor, docs, migration, test, architecture, cartography, self_construction, other)}
        {--mode= : Override scope_mode (compact|structural)}
        {--risk= : Override risk_level (low|medium|high|critical)}
        {--owner= : Optional owner identifier}
        {--workspace= : Optional workspace path}
        {--json : Print machine-readable JSON}';

    protected $description = 'Receive a programming intent and create a governed work item.';

    public function handle(ProgrammingGovernanceService $governance): int
    {
        $intent = (string) $this->argument('intent');

        try {
            $snapshot = $governance->intake($intent, $this->intakeOptions());
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($snapshot));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Programming Intake</>', $snapshot['code']);
        $this->components->twoColumnDetail('Intent type', $snapshot['intent_type']);
        $this->components->twoColumnDetail('Scope mode', $snapshot['scope_mode']);
        $this->components->twoColumnDetail('Risk level', $snapshot['risk_level']);
        $this->components->twoColumnDetail('Status', $snapshot['status']);
        $this->components->twoColumnDetail('Current stage', $snapshot['current_stage']);
        $this->newLine();
        $this->components->info('Required gates: '.implode(', ', $snapshot['required_gates']));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function intakeOptions(): array
    {
        $options = [];
        foreach (['type', 'mode', 'risk', 'owner', 'workspace'] as $key) {
            $value = $this->option($key);
            if (is_string($value) && trim($value) !== '') {
                $options[$key] = $value;
            }
        }

        return $options;
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

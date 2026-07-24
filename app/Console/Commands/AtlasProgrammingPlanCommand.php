<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyUntrimmedStringOption;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Attach a plan and task contracts to a work item.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md (Contrato 3)
 * @see docs/engineering-knowledge-base/engineering-blueprint-contracts.md
 */
class AtlasProgrammingPlanCommand extends Command
{
    use ReadsNonEmptyUntrimmedStringOption;

    use EmitsCanonicalJson;

    protected $signature = 'atlas:programming:plan
        {work_item : Work item code or UUID}
        {--from-file= : Path to a JSON plan+tasks document}
        {--from-stdin : Read JSON plan+tasks from STDIN}
        {--task=* : Repeatable JSON task contract (alternative to --from-file)}
        {--allowed-files=* : Repeatable allowed_files entry for an inline single-task plan}
        {--forbidden-files=* : Repeatable forbidden_files entry for an inline single-task plan}
        {--validation-command=* : Repeatable validation_commands entry for inline plan}
        {--acceptance=* : Repeatable acceptance_criteria entry for inline plan}
        {--rollback= : Inline rollback strategy}
        {--cartography-required : Mark inline task as requiring cartography update}
        {--json : Print machine-readable JSON}';

    protected $description = 'Attach plan and task contracts to a programming work item.';

    public function handle(ProgrammingGovernanceService $governance): int
    {
        try {
            $workItem = $governance->find((string) $this->argument('work_item'));
            [$plan, $tasks] = $this->loadPlanAndTasks();
            $payload = $governance->attachPlan($workItem, $plan, $tasks);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encodeOrEmptyObject($payload));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Programming Plan</>', $payload['code']);
        $this->components->twoColumnDetail('Plan hash', $payload['plan_hash']);
        $this->components->twoColumnDetail('Task count', (string) count($payload['tasks']));
        $this->components->twoColumnDetail('Status', $payload['status']);

        return self::SUCCESS;
    }

    /**
     * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
     */
    private function loadPlanAndTasks(): array
    {
        $fromFile = $this->option('from-file');
        if (is_string($fromFile) && $fromFile !== '') {
            $contents = @file_get_contents($fromFile);
            if ($contents === false) {
                throw new RuntimeException("plan_file_unreadable:{$fromFile}");
            }

            return $this->splitFromDocument($this->decode($contents));
        }

        if ((bool) $this->option('from-stdin')) {
            $contents = stream_get_contents(STDIN);
            if ($contents === false || trim((string) $contents) === '') {
                throw new RuntimeException('plan_stdin_empty');
            }

            return $this->splitFromDocument($this->decode((string) $contents));
        }

        $rawTasks = (array) $this->option('task');
        if ($rawTasks !== []) {
            $tasks = [];
            foreach ($rawTasks as $rawTask) {
                if (! is_string($rawTask)) {
                    continue;
                }
                $decoded = $this->decode($rawTask);
                $tasks[] = $decoded;
            }
            if ($tasks === []) {
                throw new RuntimeException('plan_inline_tasks_empty');
            }

            return [['phases' => []], array_values($tasks)];
        }

        $allowed = $this->arrayOption('allowed-files');
        $forbidden = $this->arrayOption('forbidden-files');
        $validation = $this->arrayOption('validation-command');
        $acceptance = $this->arrayOption('acceptance');

        if ($allowed === [] && $forbidden === [] && $validation === [] && $acceptance === []) {
            throw new RuntimeException('plan_input_empty');
        }

        $task = [
            'allowed_files' => $allowed,
            'forbidden_files' => $forbidden,
            'validation_commands' => $validation,
            'acceptance_criteria' => $acceptance,
            'rollback' => $this->stringOption('rollback'),
            'cartography_required' => (bool) $this->option('cartography-required'),
        ];

        return [['phases' => []], [$task]];
    }

    /**
     * @param  array<string,mixed>  $document
     * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
     */
    private function splitFromDocument(array $document): array
    {
        $tasks = array_values((array) ($document['tasks'] ?? []));
        $plan = $document;
        unset($plan['tasks']);

        return [$plan, $tasks];
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(string $contents): array
    {
        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('plan_payload_not_valid_json_object');
        }

        return $decoded;
    }


    /**
     * @return list<string>
     */
    private function arrayOption(string $key): array
    {
        return array_values(array_filter(
            (array) $this->option($key),
            static fn ($v): bool => is_string($v) && trim($v) !== '',
        ));
    }

}

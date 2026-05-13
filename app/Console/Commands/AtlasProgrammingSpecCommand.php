<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Attach a spec to a programming work item. Spec is rejected if the work item
 * is already past execution (retroactive spec is failure of process).
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md (Contrato 2)
 */
class AtlasProgrammingSpecCommand extends Command
{
    protected $signature = 'atlas:programming:spec
        {work_item : Work item code or UUID}
        {--from-file= : Path to JSON spec file}
        {--from-stdin : Read JSON spec from STDIN}
        {--objective= : Inline spec field}
        {--context= : Inline spec field}
        {--expected-behavior= : Inline spec field}
        {--likely-file=* : Repeatable: probable files affected}
        {--risk=* : Repeatable: risk entries}
        {--test=* : Repeatable: tests planned}
        {--evidence-required=* : Repeatable: evidence types required}
        {--rollback= : Inline rollback statement}
        {--completion-criterion=* : Repeatable: completion criteria}
        {--json : Print machine-readable JSON}';

    protected $description = 'Attach a canonical spec to a programming work item.';

    public function handle(ProgrammingGovernanceService $governance): int
    {
        try {
            $workItem = $governance->find((string) $this->argument('work_item'));
            $spec = $this->loadSpec();
            $payload = $governance->attachSpec($workItem, $spec);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Programming Spec</>', $payload['code']);
        $this->components->twoColumnDetail('Spec hash', $payload['spec_hash']);
        $this->components->twoColumnDetail('Status', $payload['status']);
        $this->components->twoColumnDetail('Current stage', $payload['current_stage']);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadSpec(): array
    {
        $fromFile = $this->option('from-file');
        if (is_string($fromFile) && $fromFile !== '') {
            $contents = @file_get_contents($fromFile);
            if ($contents === false) {
                throw new RuntimeException("spec_file_unreadable:{$fromFile}");
            }

            return $this->decode($contents);
        }

        if ((bool) $this->option('from-stdin')) {
            $contents = stream_get_contents(STDIN);
            if ($contents === false || trim((string) $contents) === '') {
                throw new RuntimeException('spec_stdin_empty');
            }

            return $this->decode((string) $contents);
        }

        $spec = array_filter([
            'objective' => $this->stringOption('objective'),
            'context' => $this->stringOption('context'),
            'expected_behavior' => $this->stringOption('expected-behavior'),
            'likely_files' => $this->arrayOption('likely-file'),
            'risks' => $this->arrayOption('risk'),
            'tests' => $this->arrayOption('test'),
            'evidence_required' => $this->arrayOption('evidence-required'),
            'rollback' => $this->stringOption('rollback'),
            'completion_criteria' => $this->arrayOption('completion-criterion'),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        if ($spec === []) {
            throw new RuntimeException('spec_input_empty');
        }

        return $spec;
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(string $contents): array
    {
        $decoded = json_decode($contents, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('spec_payload_not_valid_json_object');
        }

        return $decoded;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $value;
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

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}

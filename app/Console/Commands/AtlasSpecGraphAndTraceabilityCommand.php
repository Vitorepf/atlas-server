<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSpecGraphAndTraceabilityService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runtime surface for the Atlas SDD Spec Graph & Traceability contracts. With no
 * args it prints the canonical Spec Graph chain, the six Required Questions, the
 * Minimum Traceability Row schema, and a worked promotion example (one complete
 * critical requirement + one incomplete critical requirement) so the documented
 * Promotion Rule is observable.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/spec-graph-and-traceability.md
 */
class AtlasSpecGraphAndTraceabilityCommand extends Command
{
    protected $signature = 'atlas:aaeos:spec-graph-and-traceability {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and evaluate the Atlas SDD Spec Graph & traceability (chain, required questions, row schema, promotion rule).';

    public function handle(AtlasSpecGraphAndTraceabilityService $service): int
    {
        try {
            $completeRow = [
                'spec_id' => 'SPEC-001',
                'requirement_id' => 'R1',
                'acceptance_criteria_id' => 'AC1',
                'task_id' => 'T2',
                'file_path' => 'ProfileForm.tsx',
                'test_path' => 'ProfileForm.test.tsx',
                'evidence_event_id' => 'EVT-001',
            ];

            $payload = [
                'schema' => AtlasSpecGraphAndTraceabilityService::SCHEMA,
                'graph_chain' => $service->graphChain(),
                'required_questions' => $service->requiredQuestions(),
                'row_fields' => $service->rowFields(),
                'example_row_audit' => $service->auditRow($completeRow),
                'example_promotion' => $service->evaluatePromotion([
                    ['requirement_id' => 'R1', 'critical' => true, 'row' => $completeRow],
                    ['requirement_id' => 'R2', 'critical' => true, 'row' => ['spec_id' => 'SPEC-001']],
                ]),
            ];

            return $this->emit($payload);
        } catch (Throwable $e) {
            $error = [
                'schema' => AtlasSpecGraphAndTraceabilityService::SCHEMA,
                'ok' => false,
                'error' => $e->getMessage(),
            ];

            $this->line(json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        foreach ($payload as $key => $value) {
            $this->components->twoColumnDetail((string) $key, is_scalar($value) ? (string) $value : json_encode($value));
        }

        return self::SUCCESS;
    }
}

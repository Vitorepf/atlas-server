<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDataModelAndServicesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Atlas SDD Data Model & Services integrity checks.
 *
 * Runs all three documented contracts against a fully-traceable demo operation:
 *  - the seven Required Questions are all answerable (verdict: auditable);
 *  - a clean execution violates none of the five Prohibitions (verdict: allowed);
 *  - the canonical eleven-stage Service Boundaries pipeline is well-formed.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/data-model-and-services.md
 */
final class AtlasDataModelAndServicesCommand extends Command
{
    protected $signature = 'atlas:aaeos:data-model-and-services {--json : Machine-readable JSON output}';

    protected $description = 'Audit an SDD operation for traceability answerability, prohibition compliance and canonical pipeline shape.';

    public function handle(AtlasDataModelAndServicesService $service): int
    {
        try {
            $trace = [
                'operation_id' => 'op_01HCANONICALDEMO',
                'requirement_id' => 'REQ-1',
                'file_path' => 'app/Services/Example/WidgetService.php',
                'acceptance_criteria_id' => 'AC-1',
                'test_path' => 'tests/Unit/Example/WidgetServiceTest.php',
                'decision_receipt_id' => 'rcpt_01HCANONICALDEMO',
                'evidence_event_id' => 'evt_01HCANONICALDEMO',
                'assumption_ids' => ['ASM-1'],
                'drift_status' => 'clean',
            ];

            $execution = [
                'has_decision_receipt' => true,
                'wrote_code' => true,
                'files_outside_scope' => [],
                'markdown_authoritative' => true,
                'has_structured_record' => true,
                'gate_failed' => false,
                'spec_mutated_after_execution' => false,
                'learning_mutates_core_policy' => true,
                'learning_reviewed' => true,
            ];

            $result = [
                'traceability' => $service->auditTraceability($trace),
                'prohibitions' => $service->checkProhibitions($execution),
                'pipeline_shape' => $service->validatePipelineShape(
                    array_keys($service->serviceBoundaries()),
                ),
            ];
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}

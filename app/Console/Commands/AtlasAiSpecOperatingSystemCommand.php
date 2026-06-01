<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiSpecOperatingSystemService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Atlas AI Spec Operating System gate.
 *
 * Runs the four documented checks against a clean, fully-governed demo step:
 *  - the seven Hard Laws (a compliant step is allowed);
 *  - the sixteen-stage Canonical Flow order (a well-formed trace is valid);
 *  - the Green Save Button design-directive reconciliation (records divergence
 *    instead of blindly obeying);
 *  - one-shot eligibility (high confidence + gates => one_shot, else staged).
 *
 *   php artisan atlas:aaeos:spec-operating-system --json
 *
 * @see docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
 */
final class AtlasAiSpecOperatingSystemCommand extends Command
{
    protected $signature = 'atlas:aaeos:spec-operating-system {--json : Machine-readable JSON output}';

    protected $description = 'Enforce the SDD Hard Laws, canonical flow order, design-directive reconciliation and one-shot eligibility.';

    public function handle(AtlasAiSpecOperatingSystemService $service): int
    {
        try {
            $step = [
                'risky' => true,
                'has_operational_spec' => true,
                'spec_present' => true,
                'has_context' => true,
                'executing' => true,
                'has_decision_receipt' => true,
                'produced_result' => true,
                'has_evidence' => true,
                'learning_changes_critical_behavior' => true,
                'learning_reviewed' => true,
                'atlas_tree_overrides_canonical' => false,
                'contradicts_design_or_security' => false,
                'divergence_recorded' => false,
            ];

            $result = [
                'hard_laws' => $service->evaluateOperation($step),
                'canonical_flow' => $service->validateFlowOrder(
                    AtlasAiSpecOperatingSystemService::CANONICAL_FLOW,
                ),
                'design_directive' => $service->resolveDesignDirective([
                    'requested_token' => 'green',
                    'design_system_token' => 'primary',
                    'action' => 'save',
                ]),
                'one_shot' => $service->evaluateOneShotEligibility([
                    'confidence' => 'high',
                    'gates_available' => true,
                ]),
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

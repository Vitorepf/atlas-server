<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasMemoryCognitiveImmuneLearningKernelService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Memory Cognitive Immune And Learning Kernel CLI.
 *
 *   php artisan atlas:aaeos:memory-cognitive-immune-learning-kernel
 *     [--class=strategic_insight_candidate] [--scope=session] [--json]
 *
 * Read-only, deterministic. Classifies an Input Class and runs the G0..G8
 * promotion ladder against safe default signals, then emits the verdict plus
 * the default quarantine state and the 8 non-negotiable rules.
 *
 * @see docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
 */
class AtlasMemoryCognitiveImmuneLearningKernelCommand extends Command
{
    protected $signature = 'atlas:aaeos:memory-cognitive-immune-learning-kernel
        {--class=strategic_insight_candidate : Input Class to classify}
        {--scope=session : candidate scope (global|workspace|project|task|domain|session|policy)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Memory · cognitive immune gate (Input Class classify, G0-G8 promotion ladder, quarantine default, non-negotiable rules).';

    public function handle(AtlasMemoryCognitiveImmuneLearningKernelService $service): int
    {
        try {
            $class = (string) ($this->option('class') ?: 'strategic_insight_candidate');
            $scope = (string) ($this->option('scope') ?: 'session');

            // Safe default candidate: every gate signal supplied so the manifest
            // shows the happy path; scope drives the Rule 7 mode resolution.
            $candidate = [
                'input_class' => $class,
                'scope' => $scope,
                'capture_consented' => true,
                'atomic_claim' => true,
                'future_signal' => true,
                'provider_safe' => true,
                'no_contradiction' => true,
                'outcome_validated' => true,
                'scope_resolved' => true,
                'promotion_mode_set' => true,
                'probation_entered' => true,
                'promotion_mode' => 'auto',
            ];

            $payload = [
                'ok' => true,
                'schema' => AtlasMemoryCognitiveImmuneLearningKernelService::SCHEMA,
                'default_state' => $service->defaultState(),
                'classification' => $service->classify($class),
                'promotion' => $service->evaluatePromotion($candidate),
                'non_negotiable_rules' => $service->nonNegotiableRules(),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'memory_cognitive_immune_learning_kernel_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

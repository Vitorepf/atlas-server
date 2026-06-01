<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveRuntimeFailureModesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cognitive Runtime Failure Modes decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-cognitive-runtime-failure-modes [--json]
 *
 * Classifies a safe reference set of active failure modes against the documented
 * Failure Matrix and Severity ladder, emitting the worst-severity verdict, the
 * runtime posture, whether execution must be withheld, and the documented
 * recovery actions. Read-only and deterministic; it never runs a provider,
 * writes evidence or relaxes a gate.
 *
 * @see docs/engineering-knowledge-base/cognitive-runtime/failure-modes.md
 */
class AtlasCognitiveRuntimeFailureModesCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-cognitive-runtime-failure-modes {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Cognitive Runtime Failure Modes · classifies active failure modes against the documented matrix/severity ladder and emits the runtime posture + recovery actions.';

    public function handle(AtlasCognitiveRuntimeFailureModesService $service): int
    {
        try {
            // Safe reference sample: a stale canonical doc (watch) plus a privacy
            // leak (critical). The worst severity must dominate, so the verdict is
            // `critical`, execution is withheld and an audit is required.
            $verdict = $service->classify([
                AtlasCognitiveRuntimeFailureModesService::MODE_STALE_CANONICAL_DOC,
                AtlasCognitiveRuntimeFailureModesService::MODE_PRIVACY_LEAK,
            ]);

            $payload = [
                'ok' => true,
                'schema' => AtlasCognitiveRuntimeFailureModesService::FAILURE_MODES_SCHEMA,
                'verdict' => $verdict,
                'severity_ladder' => AtlasCognitiveRuntimeFailureModesService::SEVERITY_POSTURE,
                'matrix_size' => count(AtlasCognitiveRuntimeFailureModesService::FAILURE_MATRIX),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // The reference run is "healthy" (the decider worked) when the worst
            // severity correctly surfaced as critical and execution was withheld.
            $healthy = $verdict['severity'] === AtlasCognitiveRuntimeFailureModesService::SEVERITY_CRITICAL
                && $verdict['withhold_execution'] === true
                && $verdict['requires_audit'] === true;

            return $healthy ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_cognitive_runtime_failure_modes_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

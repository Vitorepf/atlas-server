<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCognitivePrinciplesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cognitive Plane principles decider CLI.
 *
 *   php artisan atlas:aaeos:cognitive-principles
 *     [--evidence-level=consensus]   // capability evidence tier to classify (C15)
 *     [--promises-gain]              // capability text promises a cognitive gain
 *     [--has-ap99]                   // an AP-99 longitudinal result exists
 *     [--has-adversarial]            // adversarial validation loop is positive
 *     [--passed-contract-test]       // capability passed its contract test
 *     [--json]
 *
 * Read-only, deterministic. Emits the evidence-level treatment for one
 * cognitive capability, plus a worked external-input filter verdict and the
 * authority resolution, so a contested/speculative capability can never become
 * a silent default.
 *
 * @see docs/engineering-knowledge-base/cognitive/principles.md
 */
class AtlasCognitivePrinciplesCommand extends Command
{
    protected $signature = 'atlas:aaeos:cognitive-principles
        {--evidence-level= : capability evidence tier (consensus|emerging|contested|speculative)}
        {--promises-gain : the capability text promises a cognitive gain}
        {--has-ap99 : an AP-99 longitudinal result exists}
        {--has-adversarial : adversarial validation loop is positive}
        {--passed-contract-test : the capability passed its contract test}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas cognitive plane · classify a capability evidence level (C15), run the external-input filter, resolve authority.';

    public function handle(AtlasCognitivePrinciplesService $service): int
    {
        try {
            $capability = [
                'evidence_level' => $this->option('evidence-level') ?: AtlasCognitivePrinciplesService::EVIDENCE_CONSENSUS,
                'promises_gain' => (bool) $this->option('promises-gain'),
                'has_ap99' => (bool) $this->option('has-ap99'),
                'has_adversarial_validation' => (bool) $this->option('has-adversarial'),
                'passed_contract_test' => (bool) $this->option('passed-contract-test'),
            ];

            $evidence = $service->classifyEvidence($capability);

            // A representative external-input run: a typical "build your own
            // gamified app" suggestion that competes with the Atlas channel.
            $filter = $service->filterExternalInput([
                'multiplies_operator_output' => false,
                'classification' => 'parallel_product',
                'violates_principle' => false,
                'absorbable_as_source' => false,
                'gain_is_proven' => false,
                'reduces_mastery_cycle' => false,
            ]);

            $authority = $service->resolveAuthority([
                AtlasCognitivePrinciplesService::AUTHORITY_COGNITIVE_PRINCIPLES,
                AtlasCognitivePrinciplesService::AUTHORITY_THESIS,
                AtlasCognitivePrinciplesService::AUTHORITY_ANTI_PATTERNS,
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'schema' => AtlasCognitivePrinciplesService::RECEIPT_SCHEMA,
                'evidence' => $evidence,
                'external_input_filter' => $filter,
                'authority' => $authority,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'cognitive_principles_evaluation_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

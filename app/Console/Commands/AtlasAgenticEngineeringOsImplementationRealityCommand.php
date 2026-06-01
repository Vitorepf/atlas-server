<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAgenticEngineeringOsImplementationRealityService;
use Illuminate\Console\Command;
use Throwable;

/**
 * AAEOS Implementation Reality policy-guard CLI.
 *
 *   php artisan atlas:aaeos:agentic-engineering-os-implementation-reality
 *     [--state=spec_only]        // declared implementation_state to classify
 *     [--evidence=code:Foo,test:BarTest,command:atlas:x,receipt:AP-1]
 *     [--no-runtime-check]       // runtime was NOT verified this session
 *     [--north-star]             // claim is strategic direction
 *     [--deprecated]             // claim is historical
 *     [--json]
 *
 * Read-only, deterministic. Emits the doc_maturity + implementation_state
 * verdict, whether it may be claimed ready, and the autonomous-loop
 * consumption decision (allow/block) under loop-strict rules.
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-engineering-os-implementation-reality.md
 */
class AtlasAgenticEngineeringOsImplementationRealityCommand extends Command
{
    protected $signature = 'atlas:aaeos:agentic-engineering-os-implementation-reality
        {--state= : declared implementation_state (runtime_verified|implemented_partial|spec_only|north_star|deprecated)}
        {--evidence= : comma-separated kind:ref proofs (code:..|command:..|route:..|test:..|receipt:..|ledger:..)}
        {--no-runtime-check : runtime was NOT verified in this session}
        {--north-star : mark the claim as strategic north-star}
        {--deprecated : mark the claim as historical/deprecated}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AAEOS · classify doc_maturity vs implementation_state and gate autonomous-loop consumption.';

    public function handle(AtlasAgenticEngineeringOsImplementationRealityService $service): int
    {
        try {
            $evidenceOpt = $this->option('evidence');
            $evidence = [];
            if (is_string($evidenceOpt) && trim($evidenceOpt) !== '') {
                foreach (explode(',', $evidenceOpt) as $token) {
                    $token = trim($token);
                    if ($token === '') {
                        continue;
                    }
                    if (str_contains($token, ':')) {
                        [$kind, $ref] = array_map('trim', explode(':', $token, 2));
                    } else {
                        $kind = $token;
                        $ref = '';
                    }
                    if ($kind !== '') {
                        $evidence[] = ['kind' => $kind, 'ref' => $ref];
                    }
                }
            }

            // Safe defaults: an unverified, no-evidence spec claim.
            $declaredState = is_string($this->option('state')) ? (string) $this->option('state') : '';

            $classification = $service->classify([
                'doc_maturity_parts' => [
                    'mother_doc' => true,
                    'contracts' => true,
                    'runbook' => true,
                    'strong_gates_evidence' => true,
                ],
                'declared_state' => $declaredState,
                'evidence' => $evidence,
                'runtime_checked' => ! (bool) $this->option('no-runtime-check'),
                'north_star' => (bool) $this->option('north-star'),
                'deprecated' => (bool) $this->option('deprecated'),
            ]);

            $loop = $service->loopConsumption([
                'implementation_state' => $classification['implementation_state'],
                'cycle_receipt' => false,
                'test_green' => $classification['evidence']['present']['test'] ?? false,
                'merge_in_scope' => false,
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'classification' => $classification,
                'loop_consumption' => $loop,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'implementation_reality_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

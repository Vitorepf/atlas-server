<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingGovernanceSystemService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Programming Governance System index decider CLI.
 *
 *   php artisan atlas:aaeos:programming-governance-system [--json]
 *
 * Read-only and deterministic. Emits the canonical governed flow, the
 * governance invariants, the full Atlas Dev Fast Lane mapping and a
 * demonstration fast-path verdict. The safe-default fast-path run is a WRITE
 * that dropped the spec law, so the decider must return fast_path=invalid —
 * proving the non-relaxation law: a cheaper lane never legalises a write
 * without spec/contract/scope/verification-evidence/completion.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system.md
 */
class AtlasProgrammingGovernanceSystemCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-governance-system {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas kernel · programming-governance-system index decider (flow, invariants, Atlas Dev fast-lane mapping, non-relaxation law).';

    public function handle(AtlasProgrammingGovernanceSystemService $service): int
    {
        try {
            // Safe-default demonstration: a write fast-path that dropped the spec
            // law must be rejected (fast_path=invalid).
            $fastPath = $service->evaluateFastPath([
                'write' => true,
                'r_level' => 'R1',
                'has_spec' => false,
                'has_task_contract' => true,
                'has_scope' => true,
                'has_verification_evidence' => true,
                'has_completion_state' => true,
                'reduced_payload' => true,
            ]);

            $payload = [
                'ok' => true,
                'flow' => $service->flow(),
                'invariants' => $service->invariants(),
                'fast_lane_mapping' => $service->fastLaneMapping(),
                'fast_path_demo' => $fastPath,
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'programming_governance_system_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

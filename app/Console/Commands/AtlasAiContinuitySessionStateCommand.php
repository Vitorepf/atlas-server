<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiContinuitySessionStateService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Continuity And Session State decider CLI.
 *
 *   php artisan atlas:aaeos:ai-continuity-session-state [--json]
 *
 * Read-only and deterministic. Resolves a full continuation plan for a sample
 * snapshot and emits the state verdict, snapshot validity, compaction
 * classification and the failure-mode mapping. With safe defaults it
 * demonstrates the contract: a `handoff` snapshot that carries no
 * parent-linked receipt is blocked (the handoff invariant), so a new execution
 * may NOT silently continue as the same operation.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md
 */
class AtlasAiContinuitySessionStateCommand extends Command
{
    protected $signature = 'atlas:aaeos:ai-continuity-session-state {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI continuity decider (can-continue verdict, snapshot completeness, compaction classification, failure modes).';

    public function handle(AtlasAiContinuitySessionStateService $service): int
    {
        try {
            // Safe default: a handoff snapshot missing its parent-linked
            // receipt — exercises the handoff invariant and the snapshot
            // completeness gate at once, so the demo verdict is "blocked".
            $sampleSnapshot = [
                'state' => AtlasAiContinuitySessionStateService::STATE_HANDOFF,
                'session_id' => 'sess_demo',
                'intent' => 'resume the in-flight work',
                // decision_receipt_id and parent_session_id intentionally absent
            ];

            $decision = $service->resolveContinuation($sampleSnapshot);
            $compaction = $service->classifyCompaction([]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision, 'compaction' => $compaction],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'ai_continuity_session_state_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

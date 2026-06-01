<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveRuntimeRunbookService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cognitive Runtime Runbook operational decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-cognitive-runtime-runbook [--json]
 *
 * Exercises the four runbook decision surfaces over safe reference inputs:
 * compaction review, stop criteria, the post-session audit packet and the
 * promotion gate. Read-only and deterministic; it never runs a provider, writes
 * evidence or relaxes a gate.
 *
 * @see docs/engineering-knowledge-base/cognitive-runtime/runbook.md
 */
class AtlasCognitiveRuntimeRunbookCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-cognitive-runtime-runbook {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Cognitive Runtime Runbook · reviews a compaction, evaluates stop criteria, emits an audit packet and decides promotion against the documented rules.';

    public function handle(AtlasCognitiveRuntimeRunbookService $service): int
    {
        try {
            // Safe reference sample: a fully-preserved, evidence-bearing,
            // privacy-redacted compaction is accepted; a clean session with no
            // stop triggers keeps running; a no-harm audit is ready; a session
            // that clears all five gates may be promoted.
            $compaction = $service->reviewCompaction([
                'preserved' => array_fill_keys(
                    AtlasCognitiveRuntimeRunbookService::REQUIRED_PRESERVED_FIELDS,
                    true,
                ),
                'evidence_refs' => ['ledger/EV-001', 'trace/T-77'],
                'needs_raw_chat' => false,
                'policy_ambiguous' => false,
                'privacy_redacted' => true,
                'canonical_conflict' => false,
            ]);

            $stop = $service->evaluateStopCriteria([
                'objective_drift_count' => 1, // one drift is tolerated
                'repeated_work_no_evidence' => false,
                'hot_file_edited_by_mistake' => false,
                'unsafe_provider_context' => false,
            ]);

            $audit = $service->evaluateAudit([
                'subject_type' => 'long_session',
                'subject_id' => 'session-reference',
                'gain' => ['useful_context' => 8, 'repeated_work_avoided' => 3, 'decision_reuse' => 2],
                'harm' => ['wrong_context' => 0, 'stale_context' => 0, 'context_contamination' => 0, 'lost_decision' => 0, 'policy_violation' => 0],
                'recommendations' => ['propose follow-up retrieval precision review'],
                'evidence_refs' => ['ledger/EV-001'],
            ]);

            $promotion = $service->evaluatePromotion([
                'snapshot_complete' => true,
                'compaction_status' => $compaction['status'],
                'audit_status' => $audit['status'],
                'audit_risks_accepted' => false,
                'validations_passed' => true,
                'replay_reconstructs' => true,
            ]);

            $payload = [
                'ok' => true,
                'schema' => AtlasCognitiveRuntimeRunbookService::RUNBOOK_SCHEMA,
                'compaction_review' => $compaction,
                'stop_criteria' => $stop,
                'audit_packet' => $audit,
                'promotion' => $promotion,
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // The reference run is healthy when the compaction is accepted, the
            // session may continue, the audit is ready and promotion is granted.
            $healthy = $compaction['accepted']
                && $stop['verdict'] === AtlasCognitiveRuntimeRunbookService::SESSION_CONTINUE
                && $audit['status'] === AtlasCognitiveRuntimeRunbookService::AUDIT_READY
                && $promotion['promote'];

            return $healthy ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_cognitive_runtime_runbook_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

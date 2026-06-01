<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCognitiveRuntimeSchemasAndPacketsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cognitive Runtime Schemas And Packets · schema guard CLI.
 *
 *   php artisan atlas:aaeos:cognitive-runtime-schemas-and-packets [--json]
 *
 * Validates safe reference instances of the four documented packets, enforces
 * the Packet Rules over a read-only packet, and decides the Promotion Rule over
 * three `ready` packet statuses. Read-only and deterministic; it never runs a
 * provider, writes evidence, promotes memory, alters policy or applies a patch.
 *
 * @see docs/engineering-knowledge-base/cognitive-runtime/schemas-and-packets.md
 */
class AtlasCognitiveRuntimeSchemasAndPacketsCommand extends Command
{
    protected $signature = 'atlas:aaeos:cognitive-runtime-schemas-and-packets {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Cognitive Runtime schemas & packets · validates snapshot, compaction, audit and retrieval-evaluation packets, enforces packet rules and decides maturity promotion.';

    public function handle(AtlasCognitiveRuntimeSchemasAndPacketsService $service): int
    {
        try {
            $svc = AtlasCognitiveRuntimeSchemasAndPacketsService::class;

            $packetRules = $service->evaluatePacketRules([
                'provider_safe' => true,
                'carries_raw_chat' => false,
                'read_only' => true,
            ]);

            $snapshot = $service->validateSnapshot([
                'schema_version' => $svc::SCHEMA_SNAPSHOT,
                'status' => 'active',
                'current_phase' => 'implementation',
                'quality_metrics' => array_fill_keys($svc::SNAPSHOT_QUALITY_METRIC_KEYS, 0),
            ]);

            $compaction = $service->validateCompactionPacket([
                'schema_version' => $svc::SCHEMA_COMPACTION,
                'source_session_id' => 'session-reference',
                'summary_hash' => 'sha256:reference',
                'preserved_decisions' => ['kept decision A'],
                'preserved_invariants' => ['hot files locked'],
                'evidence_refs' => ['ledger/EV-001'],
                'blocked_reasons' => [],
                'next_action' => 'continue implementation phase',
                'status' => 'ready',
            ]);

            $audit = $service->validateAuditPacket([
                'schema_version' => $svc::SCHEMA_AUDIT,
                'subject_type' => 'long_session',
                'subject_id' => 'session-reference',
                'status' => 'ready',
                'gain' => ['useful_context' => 8, 'repeated_work_avoided' => 3, 'decision_reuse' => 2],
                'harm' => ['wrong_context' => 0, 'stale_context' => 0, 'context_contamination' => 0, 'lost_decision' => 0, 'policy_violation' => 0],
                'net_value' => 13,
            ]);

            $retrieval = $service->validateRetrievalEvaluation(
                array_fill_keys($svc::RETRIEVAL_REQUIRED_MEASURES, 0),
            );

            $promotion = $service->evaluatePromotion([
                'snapshot_status' => 'ready',
                'compaction_status' => 'ready',
                'audit_status' => 'ready',
                'risks_accepted_by_operator' => false,
            ]);

            $payload = [
                'ok' => true,
                'schema' => $svc::RECEIPT_SCHEMA,
                'packet_rules' => $packetRules,
                'snapshot' => $snapshot,
                'compaction_packet' => $compaction,
                'audit_packet' => $audit,
                'retrieval_evaluation' => $retrieval,
                'promotion' => $promotion,
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $healthy = $packetRules['compliant']
                && $snapshot['valid']
                && $compaction['valid']
                && $audit['valid']
                && $retrieval['valid']
                && $promotion['may_promote_to_maturity_evidence'];

            return $healthy ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_cognitive_runtime_schemas_and_packets_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}

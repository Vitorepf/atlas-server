<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\Compounding\AtlasRagFeedbackService;
use App\Services\Ai\Context\Support\RetrievalFeedbackLoopSupport;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;

final class AtlasRetrievalFeedbackLoopService
{
    public const SCHEMA_VERSION = 'atlas.aucri.retrieval_feedback_loop.v1';

    public const FEEDBACK_EVENT_SCHEMA = 'atlas.aucri.feedback_event.v1';

    public const CONTEXT_ROI_SCHEMA = 'atlas.aucri.context_roi.v1';

    public const CONTEXT_REF_ATTRIBUTION_SCHEMA = 'atlas.aucri.context_ref_attribution.v1';

    public const NEXT_CONTEXT_POLICY_SCHEMA = 'atlas.aucri.next_context_policy.v1';

    public const MISSED_REF_SCHEMA = 'atlas.aucri.missed_ref_candidate.v1';

    public const NOISE_REF_SCHEMA = 'atlas.aucri.noise_ref_candidate.v1';

    public const LEARNING_CANDIDATE_SCHEMA = 'atlas.aucri.retrieval_learning_candidate.v1';

    public const EXPLICIT_UTILITY_FORMULA_VERSION = 'atlas.context.explicit_post_execution_utility.v1';

    public const MAX_CONTEXT_ATTRIBUTION_REFS = 32;

    public function __construct(
        private readonly AtlasContextFreshnessQualityGateService $freshnessQualityGate,
        private readonly AtlasRagFeedbackService $ragFeedbackService,
        private readonly AtlasContextFeedbackSignalPolicy $feedbackSignalPolicy,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function capture(array $input): array
    {
        $record = (bool) ($input['record'] ?? false);
        $outcomeStatus = RetrievalFeedbackLoopSupport::outcomeStatus((string) ($input['outcome_status'] ?? 'unknown'));
        $attributionQuality = RetrievalFeedbackLoopSupport::attributionQuality((string) ($input['attribution_quality'] ?? 'low'));
        $gate = $this->freshnessQualityGate->evaluate($input);
        $selected = (array) data_get($gate, 'freshness_report.items', []);
        $coverage = (array) data_get($gate, 'context_quality_gate.required_source_coverage', []);
        $deliveredPackLedger = $this->deliveredPackLedger($input);
        $hasLedgerDeliveredRefs = (bool) ($deliveredPackLedger['hit'] ?? false);
        $missed = RetrievalFeedbackLoopSupport::missedRefCandidates($hasLedgerDeliveredRefs ? [] : $coverage, (array) ($input['missed_required_sources'] ?? []));
        $noise = $hasLedgerDeliveredRefs
            ? RetrievalFeedbackLoopSupport::deliveredLedgerNoiseRefCandidates((array) ($deliveredPackLedger['delivered_refs'] ?? []), $input)
            : RetrievalFeedbackLoopSupport::noiseRefCandidates(
                $selected,
                (array) ($input['noise_ref_hashes'] ?? []),
                (array) ($input['noise_context_refs'] ?? []),
                $outcomeStatus,
            );
        $contextRefAttribution = RetrievalFeedbackLoopSupport::contextRefAttribution($selected, $missed, $noise, $outcomeStatus, $input, $deliveredPackLedger);
        $usedCount = (int) $contextRefAttribution['used_count'];
        $noiseCount = max(count($noise), (int) $contextRefAttribution['noise_count']);
        $roi = RetrievalFeedbackLoopSupport::contextRoi(
            $selected,
            $usedCount,
            $noiseCount,
            count($missed),
            $gate,
            $outcomeStatus,
            $input,
            (int) $contextRefAttribution['delivered_count'],
            (bool) ($contextRefAttribution['measured'] ?? false),
        );
        $nextContextPolicy = RetrievalFeedbackLoopSupport::nextContextPolicy(
            $contextRefAttribution,
            $roi,
            (bool) config('atlas.aobg.repromote_specific_refs_enabled', false),
        );
        $feedbackEvent = RetrievalFeedbackLoopSupport::feedbackEvent($gate, $roi, $missed, $noise, $contextRefAttribution, $outcomeStatus, $attributionQuality, $input, $deliveredPackLedger);
        $persisted = $record
            ? $this->persistFeedback($feedbackEvent, $roi, $missed, $noise, $contextRefAttribution, $nextContextPolicy, $outcomeStatus, $input)
            : null;
        if ($persisted instanceof AiRagFeedbackEvent) {
            $this->resolvePriorMisses($persisted, $deliveredPackLedger);
        }
        $learningCandidate = RetrievalFeedbackLoopSupport::learningCandidate(
            $feedbackEvent,
            $roi,
            $missed,
            $noise,
            $contextRefAttribution,
            $nextContextPolicy,
            $persisted?->feedback_hash,
        );
        $measured = (bool) ($roi['measured'] ?? false) && (bool) ($contextRefAttribution['measured'] ?? false);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => RetrievalFeedbackLoopSupport::status($gate, $roi, $missed, $noise, $contextRefAttribution, $outcomeStatus),
            'generated_at' => Carbon::now()->toIso8601String(),
            'measured' => $measured,
            'attribution_quality' => $attributionQuality,
            'usage_basis' => (string) ($contextRefAttribution['usage_basis'] ?? 'unknown'),
            'measurement_basis' => [
                'utility' => (string) ($roi['utility_basis'] ?? 'unknown'),
                'usage' => (string) ($contextRefAttribution['usage_basis'] ?? 'unknown'),
                'delivery' => (string) ($contextRefAttribution['delivery_basis'] ?? 'unknown'),
            ],
            'feedback_event' => RetrievalFeedbackLoopSupport::providerSafeFeedbackEvent($feedbackEvent),
            'context_roi' => $roi,
            'context_ref_attribution' => $contextRefAttribution,
            'next_context_policy' => $nextContextPolicy,
            'missed_ref_candidates' => $missed,
            'noise_ref_candidates' => $noise,
            'learning_candidate' => $learningCandidate,
            'persistence' => [
                'requested' => $record,
                'available' => DatabaseTableAvailability::has('ai_rag_feedback_events'),
                'persisted' => $persisted instanceof AiRagFeedbackEvent,
                'rag_feedback_id' => $persisted?->id,
                'feedback_hash' => $persisted?->feedback_hash,
                'schema_version' => $persisted?->schema_version,
            ],
            'policy' => [
                'auto_promote_learning' => false,
                'operator_review_required' => true,
                'feedback_without_causality_promotes_policy' => false,
                'raw_text_exposed' => false,
                'providers_invoked' => false,
            ],
            'claims' => [
                'providers_invoked' => false,
                'writes' => $persisted instanceof AiRagFeedbackEvent,
                'auto_promoted' => false,
                'benchmark_run' => false,
                'raw_text_exposed' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['retrieval_feedback_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $feedbackEvent
     * @param  array<string,mixed>  $roi
     * @param  array<int,array<string,mixed>>  $missed
     * @param  array<int,array<string,mixed>>  $noise
     * @param  array<string,mixed>  $input
     */
    private function persistFeedback(
        array $feedbackEvent,
        array $roi,
        array $missed,
        array $noise,
        array $contextRefAttribution,
        array $nextContextPolicy,
        string $outcomeStatus,
        array $input,
    ): ?AiRagFeedbackEvent {
        if (! DatabaseTableAvailability::has('ai_rag_feedback_events')) {
            return null;
        }

        return $this->ragFeedbackService->record([
            'retrieval_receipt_id' => $feedbackEvent['retrieval_receipt_id'],
            'flow_id' => $feedbackEvent['flow_id'],
            'query_plan_hash' => $feedbackEvent['query_plan_hash'],
            'included_sources' => $roi['included_sources'],
            'used_sources' => $roi['used_sources'],
            'noise_sources' => $roi['noise_sources'],
            'missed_required_sources' => $feedbackEvent['missed_required_sources'],
            'context_sufficiency' => $roi['context_sufficiency'] ?? 0,
            'post_execution_utility' => $roi['post_execution_utility'] ?? 0,
            'source_utility' => $feedbackEvent['source_utility'],
            'outcome_status' => $outcomeStatus,
            'failure_reason' => $feedbackEvent['failure_reason'],
            'next_retrieval_hint' => RetrievalFeedbackLoopSupport::nextRetrievalHint($missed, $noise, $roi, $nextContextPolicy),
            'run_outcome_id' => is_scalar($input['run_outcome_id'] ?? null) ? (string) $input['run_outcome_id'] : null,
            'memory_candidate_id' => is_scalar($input['memory_candidate_id'] ?? null) ? (string) $input['memory_candidate_id'] : null,
            'payload' => [
                'schema_version' => self::SCHEMA_VERSION,
                'freshness_quality_gate_hash' => $feedbackEvent['freshness_quality_gate_hash'],
                'measured' => (bool) ($feedbackEvent['measured'] ?? false),
                'attribution_quality' => (string) ($feedbackEvent['attribution_quality'] ?? 'low'),
                'formula_version' => $feedbackEvent['formula_version'] ?? null,
                'usage_basis' => (string) ($feedbackEvent['usage_basis'] ?? 'unknown'),
                'measurement_basis' => (array) ($feedbackEvent['measurement_basis'] ?? []),
                'delivered_pack_hashes' => (array) ($feedbackEvent['delivered_pack_hashes'] ?? []),
                'applied_policy_snapshot' => (array) ($feedbackEvent['applied_policy_snapshot'] ?? []),
                'context_roi' => $roi,
                'context_ref_attribution' => $contextRefAttribution,
                'next_context_policy' => $nextContextPolicy,
                'missed_count' => count($missed),
                'noise_count' => count($noise),
                'raw_text_exposed' => false,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{hit:bool,hashes:array<int,string>,delivered_refs:array<int,string>,entries:array<int,array<string,mixed>>}
     */
    private function deliveredPackLedger(array $input): array
    {
        $hashes = RetrievalFeedbackLoopSupport::scalarStringList($input['context_pack_hashes'] ?? []);
        if (is_scalar($input['context_pack_hash'] ?? null) && trim((string) $input['context_pack_hash']) !== '') {
            array_unshift($hashes, trim((string) $input['context_pack_hash']));
        }
        $hashes = AtlasCanonicalContextRef::uniqueStrings($hashes);

        if ($hashes === []) {
            return ['hit' => false, 'hashes' => [], 'delivered_refs' => [], 'entries' => []];
        }

        try {
            $ledger = AtlasDeliveredPackLedger::fromConfig();
            if (count($hashes) === 1) {
                $entry = $ledger->lookup($hashes[0]);
                if (! is_array($entry)) {
                    return ['hit' => false, 'hashes' => $hashes, 'delivered_refs' => [], 'entries' => []];
                }

                return [
                    'hit' => true,
                    'hashes' => $hashes,
                    'delivered_refs' => AtlasCanonicalContextRef::uniqueStrings((array) ($entry['delivered_refs'] ?? [])),
                    'entries' => [$entry],
                ];
            }

            $lookup = $ledger->lookupMany($hashes);

            return [
                'hit' => ((array) ($lookup['entries'] ?? [])) !== [],
                'hashes' => $hashes,
                'delivered_refs' => AtlasCanonicalContextRef::uniqueStrings((array) ($lookup['delivered_refs'] ?? [])),
                'entries' => (array) ($lookup['entries'] ?? []),
            ];
        } catch (\Throwable) {
            return ['hit' => false, 'hashes' => $hashes, 'delivered_refs' => [], 'entries' => []];
        }
    }

    /**
     * @param  array{hit:bool,hashes:array<int,string>,delivered_refs:array<int,string>,entries:array<int,array<string,mixed>>}  $deliveredPackLedger
     */
    private function resolvePriorMisses(AiRagFeedbackEvent $current, array $deliveredPackLedger): void
    {
        $deliveredSourceTypes = [];
        foreach ((array) ($deliveredPackLedger['delivered_refs'] ?? []) as $ref) {
            $sourceType = $this->feedbackSignalPolicy->sourceTypeFromRef((string) $ref);
            if ($sourceType !== null) {
                $deliveredSourceTypes[$sourceType] = true;
            }
        }
        if ($deliveredSourceTypes === []) {
            return;
        }

        AiRagFeedbackEvent::query()
            ->where('flow_id', $current->flow_id)
            ->where('id', '!=', $current->id)
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->each(function (AiRagFeedbackEvent $event) use ($deliveredSourceTypes, $current): void {
                if (! $this->feedbackSignalPolicy->isMeasuredAggregateEligible($event)) {
                    return;
                }

                $missed = RetrievalFeedbackLoopSupport::scalarStringList($event->missed_required_sources ?? []);
                if ($missed === []) {
                    return;
                }

                $alreadyResolved = RetrievalFeedbackLoopSupport::scalarStringList(data_get($event->payload, 'payload.missed_resolution.resolved_source_types', []));
                $newlyResolved = [];
                foreach ($missed as $sourceType) {
                    if (isset($deliveredSourceTypes[$sourceType]) && ! in_array($sourceType, $alreadyResolved, true)) {
                        $newlyResolved[] = $sourceType;
                    }
                }
                if ($newlyResolved === []) {
                    return;
                }

                $resolved = array_values(array_unique(array_merge($alreadyResolved, $newlyResolved)));
                $payload = is_array($event->payload) ? $event->payload : [];
                $payload['payload'] = (array) ($payload['payload'] ?? []);
                $payload['payload']['missed_resolution'] = [
                    'schema_version' => 'atlas.aucri.missed_resolution.v1',
                    'status' => count(array_diff($missed, $resolved)) === 0 ? 'resolved' : 'partially_resolved',
                    'resolved_source_types' => $resolved,
                    'resolved_by_feedback_hash' => (string) $current->feedback_hash,
                    'resolved_by_delivered_pack_hashes' => (array) data_get($current->payload, 'payload.delivered_pack_hashes', []),
                    'resolution_scope' => 'source_type',
                ];
                $event->forceFill(['payload' => $payload])->save();
            });
    }

}

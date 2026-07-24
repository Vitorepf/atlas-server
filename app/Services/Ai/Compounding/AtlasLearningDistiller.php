<?php

namespace App\Services\Ai\Compounding;

use App\Models\AiLearningCandidate;
use App\Models\AiRunOutcome;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskOutcomeCausalAttributor;

class AtlasLearningDistiller
{
    use CompoundingArrayHelper;

    public const SCHEMA_VERSION = 'atlas.ai.compounding.learning_candidate.v1';

    /**
     * ASI-09 — author≠judge seam. The adapter AUTHORS the claim text; the judges
     * downstream (CaptureQualityGate + false_learning_gate + ASI-02 admission)
     * remain deterministic and untouched. Optional to preserve byte-identical
     * behavior when `atlas.ai.distiller.model_author_enabled` is OFF (default).
     */
    public function __construct(
        private readonly ?DistillerAuthorAdapter $author = null,
        private readonly ?AtlasExternalBrainTaskOutcomeCausalAttributor $causalAttributor = null,
    ) {}

    /**
     * @param  array<string,mixed>  $signals
     */
    public function distill(AiRunOutcome $outcome, array $signals = []): AiLearningCandidate
    {
        $evidenceRefs = $this->array($signals['evidence_refs'] ?? $outcome->evidence_refs ?? []);
        $signalsClaim = $this->string($signals['claim'] ?? null);

        // ASI-09 — model AUTHOR seam (default-OFF). Only engaged when (a) the flag
        // is ON, (b) an adapter is bound, and (c) the caller did not already
        // provide a claim in $signals (caller-provided claims are authoritative).
        // The adapter may return null (missing inputs, provider unreachable,
        // privacy class local-only mismatch, …); we fall back to the deterministic
        // template — the JUDGES downstream evaluate whichever claim wins.
        $authorMeta = null;
        if ($signalsClaim === null
            && $this->author !== null
            && (bool) config('atlas.ai.distiller.model_author_enabled', false)
        ) {
            $authored = $this->author->authorClaim($outcome, $signals);
            if (is_array($authored)) {
                $authoredClaim = $this->string($authored['claim'] ?? null);
                if ($authoredClaim !== null) {
                    $signalsClaim = $authoredClaim;
                    // If the caller did not supply refs, adopt the ones the
                    // author cited so the JUDGES can verify.
                    if ($evidenceRefs === []) {
                        $authoredRefs = $authored['evidence_refs'] ?? [];
                        if (is_array($authoredRefs)) {
                            $evidenceRefs = array_values(array_filter(
                                $authoredRefs,
                                static fn ($ref): bool => is_string($ref) && $ref !== '',
                            ));
                        }
                    }
                    $authorMeta = [
                        'source' => 'model_author',
                        'adapter' => $this->author::class,
                    ];
                }
            }
        }

        $claim = $signalsClaim ?? $this->defaultClaim($outcome);
        $confidence = $this->score($signals['confidence'] ?? null, $outcome->evidence_quality);
        $decision = $evidenceRefs === [] ? 'hold' : ($confidence >= 70 ? 'promote' : 'hold');
        $missingEvidence = match (true) {
            $evidenceRefs === [] => ['evidence_refs'],
            $confidence < 70 => ['confidence_below_70'],
            default => [],
        };

        // Capture quality gate no CHOKEPOINT da memória (T1.2): TODO produtor de
        // recordExecution passa por aqui antes de virar memória. enforce ⇒ claim
        // boilerplate/fixture/contentless NUNCA promove (held_low_quality);
        // observe ⇒ só anota. Fecha na estrutura o buraco da semana de 96% ruído.
        $gate = app(AtlasCaptureQualityGate::class)->assess([
            'kind' => 'compounding_memory',
            'claim' => $claim,
            'content' => $this->array($signals),
        ]);
        $mode = (string) config('atlas.ai.capture_quality_gate.mode', 'observe');
        $heldByGate = $mode === 'enforce' && $gate['admit'] === false;
        if ($heldByGate) {
            $decision = 'hold';
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_outcome_id' => $outcome->id,
            'status' => $decision === 'promote'
                ? 'ready_for_promotion'
                : ($heldByGate ? 'held_low_quality' : 'held_for_evidence'),
            'decision' => $decision,
            'memory_type' => $this->string($signals['memory_type'] ?? null) ?? $this->memoryTypeFor($outcome->flow_id),
            'scope' => $this->string($signals['scope'] ?? null) ?? 'global',
            'claim' => $claim,
            'confidence' => $confidence,
            'promotion_allowed' => $decision === 'promote',
            'evidence_refs' => $evidenceRefs,
            'payload' => [
                'signals' => $signals,
                'outcome_hash' => $outcome->outcome_hash,
                'missing_evidence' => $missingEvidence,
                'capture_quality' => $mode === 'off' ? null : [
                    'admit' => $gate['admit'],
                    'reason' => $gate['reason'],
                    'score' => $gate['quality_score'],
                    'mode' => $mode,
                ],
            ],
            'decided_at' => now(),
        ];
        $causedBy = $this->causedBy($outcome, $signals, $evidenceRefs);
        if ($causedBy !== null) {
            $payload['payload']['caused_by'] = $causedBy;
        }
        // ASI-09 audit — record which side of author≠judge produced the claim
        // (only when the model-author actually authored; template path leaves
        // the field absent so the OFF path is byte-identical).
        if ($authorMeta !== null) {
            $payload['payload']['author'] = $authorMeta;
        }
        $payload['candidate_hash'] = CompoundingHash::make([
            'schema' => self::SCHEMA_VERSION,
            'run_outcome_id' => $outcome->id,
            'claim' => $claim,
            'evidence_refs' => $evidenceRefs,
        ]);
        $payload['receipt_hash'] = CompoundingHash::make($payload);

        return AiLearningCandidate::query()->firstOrCreate(
            ['candidate_hash' => $payload['candidate_hash']],
            $payload,
        );
    }

    private function defaultClaim(AiRunOutcome $outcome): string
    {
        return sprintf(
            'Flow %s produced %s outcome and should inform future routing, retrieval or execution when matching evidence recurs.',
            $outcome->flow_id,
            $outcome->outcome_status,
        );
    }

    private function memoryTypeFor(string $flowId): string
    {
        return match ($flowId) {
            'atlas_debug' => 'debug_memory',
            'atlas_review' => 'review_memory',
            'atlas_forge' => 'forge_handoff_memory',
            'atlas_research' => 'retrieval_memory',
            default => 'routing_memory',
        };
    }

    /**
     * @param  array<string,mixed>  $signals
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>|null
     */
    private function causedBy(AiRunOutcome $outcome, array $signals, array $evidenceRefs): ?array
    {
        if (! (bool) config('atlas.ai.credit_assignment.enabled', false)) {
            return null;
        }

        $input = [
            'spec' => $this->array($signals['spec'] ?? []),
            'worker' => $this->array($signals['worker'] ?? []),
            'queue' => $this->array($signals['queue'] ?? []),
            'outcome' => $this->array($signals['outcome'] ?? []) + [
                'result' => (string) $outcome->outcome_status,
                'had_evidence' => $evidenceRefs !== [],
            ],
            'task_packet_id' => $this->string($signals['task_packet_id'] ?? $outcome->run_id ?? null),
            'family' => $this->string($signals['task_class'] ?? $outcome->flow_id ?? null),
        ];

        $attribution = ($this->causalAttributor ?? new AtlasExternalBrainTaskOutcomeCausalAttributor)
            ->attribute($input);

        return [
            'schema' => (string) ($attribution['schema'] ?? AtlasExternalBrainTaskOutcomeCausalAttributor::SCHEMA),
            'primary_cause' => (string) ($attribution['primary_cause'] ?? AtlasExternalBrainTaskOutcomeCausalAttributor::CAUSE_UNKNOWN),
            'contributing_causes' => array_values((array) ($attribution['contributing_causes'] ?? [])),
            'confidence' => (string) ($attribution['confidence'] ?? 'low'),
            'recommended_originator_adjustment' => (string) ($attribution['recommended_originator_adjustment'] ?? ''),
            'attribution_id' => (string) ($attribution['attribution_id'] ?? ''),
            'refs' => $evidenceRefs,
        ];
    }

    /**
     * @return array<int|string,mixed>
     */
    private function score(mixed $value, int $default): int
    {
        if (! is_numeric($value)) {
            return max(0, min(100, $default));
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

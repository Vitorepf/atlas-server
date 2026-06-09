<?php

namespace App\Services\Ai\Context;

use App\Services\Ai\Cognitive\ClaimCoherence\ClaimQualifierStrengthClassifier;
use App\Services\Ai\Cognitive\ClaimCoherence\ClaimSelfCoherenceScorer;
use App\Services\Ai\Cognitive\ClaimCoherence\HedgeCertaintyConflictDetector;
use App\Services\Ai\ValueObjects\AiContextPack;
use Illuminate\Support\Str;

class ContextPackSelfReflectionGate
{
    public const STATUS_SUFFICIENT = 'sufficient';

    public const STATUS_INSUFFICIENT = 'insufficient';

    public const STATUS_CONTRADICTORY = 'contradictory';

    public const STATUS_RISKY = 'risky';

    /**
     * Consolidated claim-coherence kernels (lazily constructed; pure, zero ctor
     * deps each). All three are reachable from the LIVE assess() path behind
     * default-OFF config flags under `atlas.claim_coherence`:
     *   - HedgeCertaintyConflictDetector -> assess()->hasContradiction()
     *   - ClaimQualifierStrengthClassifier -> assess()->hasLowClaimCoherence()
     *   - ClaimSelfCoherenceScorer        -> assess()->hasLowClaimCoherence()
     * assess() itself is consumed live by AtlasContextIntelligenceService::certifyContext()
     * and AtlasOpenBrainContextInjectionService::selfReflection(). With every flag OFF the
     * assess() output is byte-identical to the pre-wiring behavior. The last two kernels are
     * additionally exposed as the direct scoreClaimSelfCoherence()/classifyQualifierStrength()
     * helpers (also default-OFF).
     */
    private ?HedgeCertaintyConflictDetector $hedgeCertaintyConflictDetector;

    private ?ClaimSelfCoherenceScorer $claimSelfCoherenceScorer;

    private ?ClaimQualifierStrengthClassifier $claimQualifierStrengthClassifier;

    public function __construct(
        ?HedgeCertaintyConflictDetector $hedgeCertaintyConflictDetector = null,
        ?ClaimSelfCoherenceScorer $claimSelfCoherenceScorer = null,
        ?ClaimQualifierStrengthClassifier $claimQualifierStrengthClassifier = null,
    ) {
        $this->hedgeCertaintyConflictDetector = $hedgeCertaintyConflictDetector;
        $this->claimSelfCoherenceScorer = $claimSelfCoherenceScorer;
        $this->claimQualifierStrengthClassifier = $claimQualifierStrengthClassifier;
    }

    /**
     * @return array<string,mixed>
     */
    public function assess(AiContextPack|array $contextPack): array
    {
        $data = $contextPack instanceof AiContextPack ? $contextPack->toArray() : $contextPack;
        $contextRefs = $contextPack instanceof AiContextPack ? $contextPack->contextRefs() : (array) data_get($data, 'context_refs', []);
        $counts = $this->counts($data, $contextRefs);
        $reasons = [];
        $status = self::STATUS_SUFFICIENT;

        if ($this->hasContradiction($data, $contextRefs)) {
            $status = self::STATUS_CONTRADICTORY;
            $reasons[] = 'context_contains_contradiction_signal';
        } elseif ($this->isRisky($data) || $this->hasLowClaimCoherence($data, $contextRefs, $counts)) {
            $status = self::STATUS_RISKY;
            $reasons[] = 'context_requires_careful_review_before_execution';
        } elseif ($this->isInsufficient($counts)) {
            $status = self::STATUS_INSUFFICIENT;
            $reasons[] = 'context_has_no_reusable_sources';
        }

        if ($reasons === []) {
            $reasons[] = 'context_has_reusable_sources';
        }

        return [
            'schema_version' => 'atlas.context_pack.self_reflection.v1',
            'status' => $status,
            'reasons' => $reasons,
            'counts' => $counts,
            'recommended_action' => $this->recommendedAction($status),
            'assessed_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<int,mixed>  $contextRefs
     * @return array<string,int>
     */
    private function counts(array $data, array $contextRefs): array
    {
        return [
            'context_refs' => count($contextRefs),
            'recent_turns' => count((array) data_get($data, 'conversation.recent_turns', [])),
            'memory_recall' => count((array) data_get($data, 'memory.recall', [])),
            'memory_registry' => count((array) data_get($data, 'memory.registry', [])),
            'memory_verbatim' => count((array) data_get($data, 'memory.verbatim', [])),
            'memory_semantic' => count((array) data_get($data, 'memory.semantic', [])),
            'open_questions' => count((array) data_get($data, 'open_questions', [])),
            'excluded_context' => count((array) data_get($data, 'excluded_context', [])),
        ];
    }

    /**
     * @param  array<string,int>  $counts
     */
    private function isInsufficient(array $counts): bool
    {
        return ($counts['context_refs']
            + $counts['recent_turns']
            + $counts['memory_recall']
            + $counts['memory_registry']
            + $counts['memory_verbatim']
            + $counts['memory_semantic']) === 0;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function isRisky(array $data): bool
    {
        return in_array((string) data_get($data, 'task.risk_level'), ['high', 'irreversible'], true)
            || (bool) data_get($data, 'gates.human_approval_required', false)
            || in_array((string) data_get($data, 'constraints.privacy_class'), ['private', 'sensitive', 'secret'], true);
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<int,mixed>  $contextRefs
     */
    private function hasContradiction(array $data, array $contextRefs): bool
    {
        $haystack = json_encode([
            'open_questions' => data_get($data, 'open_questions', []),
            'excluded_context' => data_get($data, 'excluded_context', []),
            'memory' => data_get($data, 'memory', []),
            'context_refs' => $contextRefs,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        $substringContradiction = Str::of($haystack)->lower()->contains([
            'contradiction',
            'contradictory',
            'contraditorio',
            'contraditório',
            'contradiz',
            'conflict',
            'conflito',
        ]);

        // Opt-in (default-OFF) — augment the substring scan with a real hedge-vs-absolute
        // certainty conflict detected by the consolidated HedgeCertaintyConflictDetector
        // kernel over the same context haystack. New behavior; skipped entirely (so the
        // verdict is unchanged) while `atlas.claim_coherence.hedge_certainty_conflict_enabled`
        // is OFF.
        return $substringContradiction || $this->hasHedgeCertaintyConflict($haystack);
    }

    /**
     * Opt-in (default-OFF) hedge-vs-absolute certainty conflict over the context haystack,
     * powered by the consolidated HedgeCertaintyConflictDetector kernel. Returns false
     * (no effect on the verdict) unless the flag is ON.
     */
    private function hasHedgeCertaintyConflict(string $haystack): bool
    {
        if (! (bool) config('atlas.claim_coherence.hedge_certainty_conflict_enabled', false)) {
            return false;
        }

        $tokens = preg_split('/[^a-z]+/', strtolower($haystack), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $detector = $this->hedgeCertaintyConflictDetector ??= new HedgeCertaintyConflictDetector;

        return (bool) ($detector->detect($tokens)['conflict'] ?? false);
    }

    /**
     * Opt-in (default-OFF) live claim-coherence risk signal over the same context
     * haystack, powered by the consolidated ClaimQualifierStrengthClassifier and
     * ClaimSelfCoherenceScorer kernels. This is the real call-chain that makes both
     * kernels REACHABLE from the live assess() consumers (AtlasContextIntelligence-
     * Service::certifyContext + AtlasOpenBrainContextInjectionService::selfReflection):
     * a context that asserts hard modal claims ("must/shall/required") while carrying
     * NO reusable evidence sources is treated as RISKY (review-before-execution).
     *
     * New behavior; returns false (verdict byte-identical to the pre-wiring path) while
     * `atlas.claim_coherence.qualifier_strength_enabled` /
     * `atlas.claim_coherence.self_coherence_enabled` are OFF.
     *
     * @param  array<string,mixed>  $data
     * @param  array<int,mixed>  $contextRefs
     * @param  array<string,int>  $counts
     */
    private function hasLowClaimCoherence(array $data, array $contextRefs, array $counts): bool
    {
        $qualifierStrengthEnabled = (bool) config('atlas.claim_coherence.qualifier_strength_enabled', false);
        $selfCoherenceEnabled = (bool) config('atlas.claim_coherence.self_coherence_enabled', false);

        if (! $qualifierStrengthEnabled && ! $selfCoherenceEnabled) {
            return false;
        }

        $haystack = $this->claimHaystack($data, $contextRefs);
        $tokens = preg_split('/[^a-z]+/', strtolower($haystack), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $reusableSourceCount = $this->reusableSourceCount($counts);

        // Qualifier-strength kernel: a "hard" modal band with zero reusable evidence
        // is an over-asserted, unsupported context — risky.
        $band = 'none';
        if ($qualifierStrengthEnabled) {
            $classified = ($this->claimQualifierStrengthClassifier ??= new ClaimQualifierStrengthClassifier)
                ->classify($tokens);
            $band = (string) ($classified['band'] ?? 'none');

            if ($band === 'hard' && $reusableSourceCount === 0) {
                return true;
            }
        }

        // Self-coherence kernel: model the whole context pack as one synthetic claim
        // (qualifier = the haystack; evidence = reusable-source count). A pack with NO
        // reusable evidence is implicitly asserting "trust me" with high confidence, so
        // asserted confidence is high exactly when evidence is absent (a hard modal band
        // pushes it higher still). An "incoherent" score is risky.
        if ($selfCoherenceEnabled) {
            $assertedConfidence = $reusableSourceCount === 0 ? 0.9 : 0.6;

            if ($band === 'hard') {
                $assertedConfidence = 0.95;
            }

            $score = ($this->claimSelfCoherenceScorer ??= new ClaimSelfCoherenceScorer)
                ->score(
                    (string) data_get($data, 'task.type', 'context'),
                    $haystack,
                    $haystack,
                    $assertedConfidence,
                    $reusableSourceCount,
                );

            if (($score['status'] ?? null) === 'incoherent') {
                return true;
            }
        }

        return false;
    }

    /**
     * Reusable-evidence-source count used by the claim-coherence kernels: the same
     * sources isInsufficient() treats as "reusable", excluding open_questions and
     * excluded_context (which are problem/exclusion signals, not evidence).
     *
     * @param  array<string,int>  $counts
     */
    private function reusableSourceCount(array $counts): int
    {
        return (int) ($counts['context_refs'] ?? 0)
            + (int) ($counts['recent_turns'] ?? 0)
            + (int) ($counts['memory_recall'] ?? 0)
            + (int) ($counts['memory_registry'] ?? 0)
            + (int) ($counts['memory_verbatim'] ?? 0)
            + (int) ($counts['memory_semantic'] ?? 0);
    }

    /**
     * The free-text claim surface of the context pack (task + memory + open questions
     * + context refs), mirroring the haystack hasContradiction() already scans.
     *
     * @param  array<string,mixed>  $data
     * @param  array<int,mixed>  $contextRefs
     */
    private function claimHaystack(array $data, array $contextRefs): string
    {
        return json_encode([
            'task' => data_get($data, 'task', []),
            'memory' => data_get($data, 'memory', []),
            'open_questions' => data_get($data, 'open_questions', []),
            'context_refs' => $contextRefs,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * Opt-in (default-OFF) — score a single claim's self-coherence via the consolidated
     * ClaimSelfCoherenceScorer kernel. New behavior; returns null when
     * `atlas.claim_coherence.self_coherence_enabled` is OFF.
     *
     * @return array{schema_version: string, coherence: float, penalties: list<string>, status: string}|null
     */
    public function scoreClaimSelfCoherence(
        string $subject,
        string $predicate,
        string $qualifier,
        float $assertedConfidence,
        int $evidenceRefCount
    ): ?array {
        if (! (bool) config('atlas.claim_coherence.self_coherence_enabled', false)) {
            return null;
        }

        return ($this->claimSelfCoherenceScorer ??= new ClaimSelfCoherenceScorer)
            ->score($subject, $predicate, $qualifier, $assertedConfidence, $evidenceRefCount);
    }

    /**
     * Opt-in (default-OFF) — classify the modal-qualifier strength of a claim via the
     * consolidated ClaimQualifierStrengthClassifier kernel. New behavior; returns null
     * when `atlas.claim_coherence.qualifier_strength_enabled` is OFF.
     *
     * @param  list<string>  $qualifierTokens
     * @return array{schema_version: string, modalCount: int, band: string, status: string}|null
     */
    public function classifyQualifierStrength(array $qualifierTokens): ?array
    {
        if (! (bool) config('atlas.claim_coherence.qualifier_strength_enabled', false)) {
            return null;
        }

        return ($this->claimQualifierStrengthClassifier ??= new ClaimQualifierStrengthClassifier)
            ->classify($qualifierTokens);
    }

    private function recommendedAction(string $status): string
    {
        return match ($status) {
            self::STATUS_SUFFICIENT => 'continue_with_context',
            self::STATUS_INSUFFICIENT => 'refresh_or_request_context',
            self::STATUS_CONTRADICTORY => 'surface_conflict_before_execution',
            self::STATUS_RISKY => 'require_review_before_execution',
            default => 'review_context_gate',
        };
    }
}

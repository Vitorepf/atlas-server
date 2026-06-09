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
     * deps each). Wired in behind default-OFF config flags under `atlas.claim_coherence`
     * — with every flag OFF the live assess() path does NOT use them and its output is
     * byte-identical to the pre-wiring behavior.
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
        } elseif ($this->isRisky($data)) {
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

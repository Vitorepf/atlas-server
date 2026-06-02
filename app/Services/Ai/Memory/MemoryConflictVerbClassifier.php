<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

/**
 * Atlas Cognition Operating System — Deep Cores (Memory Conflict Verbs).
 *
 * Pure classifier that decides the relation verb between two memory facts and
 * whether the relation must escalate to a human. Mirrors the canonical lexicon
 * from AtlasMemoryConflictResolutionService (VISIBLE_VERDICTS +
 * HIGH_RISK_MEMORY_TYPES) byte-for-byte. Zero ctor deps, no clock/I/O/DB.
 *
 * Each input is a fact: {key, scope_type, polarity:'affirm'|'negate',
 * recorded_ts:int, memory_type}. The verdict is computed from <=7 ordered rules.
 */
final class MemoryConflictVerbClassifier
{
    private const SCHEMA_VERSION = 'atlas.memory.conflict_verb.v1';

    private const VERDICT_RELATED = 'related';

    private const VERDICT_COMPATIBLE = 'compatible';

    private const VERDICT_SCOPED = 'scoped';

    private const VERDICT_CONFLICTS_WITH = 'conflicts_with';

    private const VERDICT_SUPERSEDES = 'supersedes';

    private const VERDICT_NOT_CONFLICT = 'not_conflict';

    private const POLARITY_AFFIRM = 'affirm';

    private const POLARITY_NEGATE = 'negate';

    /**
     * Verdicts that change display in search — mirror of
     * AtlasMemoryConflictResolutionService::VISIBLE_VERDICTS.
     *
     * @var list<string>
     */
    private const VISIBLE_VERDICTS = [
        self::VERDICT_CONFLICTS_WITH,
        self::VERDICT_SUPERSEDES,
    ];

    /**
     * Memory types that trigger human escalation on visible verdicts — mirror of
     * AtlasMemoryConflictResolutionService::HIGH_RISK_MEMORY_TYPES.
     *
     * @var list<string>
     */
    private const HIGH_RISK_MEMORY_TYPES = [
        'decision',
        'architecture',
        'policy',
    ];

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @return array{schema_version: string, verdict: string, escalate: bool, reason: string}
     */
    public function classify(array $a, array $b): array
    {
        $keyA = $this->stringField($a, 'key');
        $keyB = $this->stringField($b, 'key');

        $scopeA = $this->stringField($a, 'scope_type');
        $scopeB = $this->stringField($b, 'scope_type');

        $polarityA = $this->polarity($a);
        $polarityB = $this->polarity($b);

        $tsA = $this->intField($a, 'recorded_ts');
        $tsB = $this->intField($b, 'recorded_ts');

        [$verdict, $reason] = $this->resolveVerdict(
            $keyA,
            $keyB,
            $scopeA,
            $scopeB,
            $polarityA,
            $polarityB,
            $tsA,
            $tsB,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'escalate' => $this->shouldEscalate($verdict, $a, $b),
            'reason' => $reason,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveVerdict(
        string $keyA,
        string $keyB,
        string $scopeA,
        string $scopeB,
        string $polarityA,
        string $polarityB,
        int $tsA,
        int $tsB,
    ): array {
        // Rule 1: keys differ -> not_conflict.
        if ($keyA !== $keyB) {
            return [self::VERDICT_NOT_CONFLICT, 'rule_1_keys_differ'];
        }

        // Rule 6: same key but a side has missing/empty scope_type -> related.
        if ($scopeA === '' || $scopeB === '') {
            return [self::VERDICT_RELATED, 'rule_6_missing_scope_type'];
        }

        // Rule 2: same key + different scope_type -> scoped.
        if ($scopeA !== $scopeB) {
            return [self::VERDICT_SCOPED, 'rule_2_different_scope_type'];
        }

        // Rule 3: same key + scope + same polarity -> compatible.
        if ($polarityA === $polarityB) {
            return [self::VERDICT_COMPATIBLE, 'rule_3_same_polarity'];
        }

        // Rule 4: same key + scope + opposite polarity + one side strictly-newer ts -> supersedes.
        if ($tsA !== $tsB) {
            return [self::VERDICT_SUPERSEDES, 'rule_4_opposite_polarity_newer_supersedes'];
        }

        // Rule 5: same key + scope + opposite polarity + equal ts -> conflicts_with.
        return [self::VERDICT_CONFLICTS_WITH, 'rule_5_opposite_polarity_equal_ts'];
    }

    /**
     * escalate=true iff verdict is visible AND either side touches a high-risk
     * memory type. Equal-ts conflicts_with on a high-risk type therefore always
     * escalates.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private function shouldEscalate(string $verdict, array $a, array $b): bool
    {
        if (! in_array($verdict, self::VISIBLE_VERDICTS, true)) {
            return false;
        }

        return $this->touchesHighRisk($a) || $this->touchesHighRisk($b);
    }

    /**
     * @param  array<string,mixed>  $fact
     */
    private function touchesHighRisk(array $fact): bool
    {
        return in_array($this->stringField($fact, 'memory_type'), self::HIGH_RISK_MEMORY_TYPES, true);
    }

    /**
     * @param  array<string,mixed>  $fact
     */
    private function polarity(array $fact): string
    {
        return $this->stringField($fact, 'polarity') === self::POLARITY_NEGATE
            ? self::POLARITY_NEGATE
            : self::POLARITY_AFFIRM;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function intField(array $payload, string $key): int
    {
        $value = $payload[$key] ?? 0;

        return is_int($value) ? $value : (int) $value;
    }
}

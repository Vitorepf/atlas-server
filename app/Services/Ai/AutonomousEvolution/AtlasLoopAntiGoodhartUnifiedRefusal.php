<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * Unified anti-Goodhart refusal verdict, FACT-only by construction.
 *
 *   - Refusal sources: proxy, farm, paraphrase, constitution.
 *   - Verdict is IMMUTABLE: refused()/reasons()/evidenceRefs(); NO numeric score field anywhere.
 *   - Each reason is a structured FACT: {source, pattern_id, fact: array, severity, evidence_refs: list<string>}.
 *   - Severity is a discrete enum string (low|medium|high|critical), NEVER a normalized scalar.
 *
 * The verdict is read-only: callers MAY enumerate reasons() to act on a refusal, but they MAY NOT rank or
 * sum severities into a single score — the contract is intentionally anti-Goodhart.
 */
final class AtlasLoopAntiGoodhartUnifiedRefusal
{
    public const SOURCE_PROXY = 'proxy';

    public const SOURCE_FARM = 'farm';

    public const SOURCE_PARAPHRASE = 'paraphrase';

    public const SOURCE_CONSTITUTION = 'constitution';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    private const VALID_SOURCES = [
        self::SOURCE_PROXY,
        self::SOURCE_FARM,
        self::SOURCE_PARAPHRASE,
        self::SOURCE_CONSTITUTION,
    ];

    private const VALID_SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    /**
     * Build a verdict from a list of candidate refusal facts. Each fact MUST carry source+pattern_id+fact;
     * malformed facts are dropped (never silently converted into refusal). A verdict with zero valid facts
     * is `refused() === false`.
     *
     * @param  list<array{source:string, pattern_id:string, fact:array<string,mixed>, severity?:string, evidence_refs?:list<string>}>  $candidateFacts
     */
    public static function verdict(array $candidateFacts): AtlasLoopAntiGoodhartRefusalVerdict
    {
        $kept = [];
        foreach ($candidateFacts as $row) {
            if (! is_array($row)) {
                continue;
            }
            $source = (string) ($row['source'] ?? '');
            $patternId = (string) ($row['pattern_id'] ?? '');
            $fact = $row['fact'] ?? null;
            if (! in_array($source, self::VALID_SOURCES, true) || $patternId === '' || ! is_array($fact)) {
                continue;
            }
            $severity = (string) ($row['severity'] ?? self::SEVERITY_MEDIUM);
            if (! in_array($severity, self::VALID_SEVERITIES, true)) {
                $severity = self::SEVERITY_MEDIUM;
            }
            $evidenceRefs = array_values(array_map(static fn ($r): string => (string) $r, (array) ($row['evidence_refs'] ?? [])));

            $kept[] = [
                'source' => $source,
                'pattern_id' => $patternId,
                'fact' => $fact,
                'severity' => $severity,
                'evidence_refs' => $evidenceRefs,
            ];
        }

        return new AtlasLoopAntiGoodhartRefusalVerdict($kept);
    }
}

/**
 * Immutable verdict carrier. Exposes:
 *   refused()       → bool
 *   reasons()       → list<FACT>   (each row in the fact-only canonical shape)
 *   evidenceRefs()  → list<string> (deduplicated, order-preserving union across all reasons)
 *
 * NO numeric score field. NO toScalar(). NO ordering by an aggregate magnitude.
 */
final readonly class AtlasLoopAntiGoodhartRefusalVerdict
{
    /**
     * @param  list<array{source:string, pattern_id:string, fact:array<string,mixed>, severity:string, evidence_refs:list<string>}>  $reasons
     */
    public function __construct(private array $reasons) {}

    public function refused(): bool
    {
        return $this->reasons !== [];
    }

    /**
     * @return list<array{source:string, pattern_id:string, fact:array<string,mixed>, severity:string, evidence_refs:list<string>}>
     */
    public function reasons(): array
    {
        return $this->reasons;
    }

    /**
     * @return list<string>
     */
    public function evidenceRefs(): array
    {
        $seen = [];
        foreach ($this->reasons as $row) {
            foreach ($row['evidence_refs'] as $ref) {
                $seen[$ref] = true;
            }
        }

        return array_values(array_keys($seen));
    }
}

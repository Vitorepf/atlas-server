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

    /**
     * Deterministic template fingerprint for a task context. Stable hash + normalized family facts so callers
     * can detect repeated task families (template farms) without fabricating examples.
     *
     * @param  array<string,mixed>  $taskContext
     * @return array{hash:string, normalized_objective:string, allowed_files_shape:string, acceptance_pattern:string}
     */
    public static function templateFingerprint(array $taskContext): array
    {
        $objective = (string) preg_replace('/\s+/', ' ', trim((string) preg_replace('/[0-9]+/', '', strtolower(trim((string) ($taskContext['objective'] ?? ''))))));

        $files = is_array($taskContext['allowed_files'] ?? null) ? array_map('strval', (array) $taskContext['allowed_files']) : [];
        sort($files, SORT_STRING);
        $filesShape = implode(',', array_map(static fn (string $f): string => pathinfo($f, PATHINFO_EXTENSION) ?: 'dir', $files));

        $criteria = is_array($taskContext['acceptance_criteria'] ?? null) ? (array) $taskContext['acceptance_criteria'] : [];
        $acceptPattern = (string) preg_replace('/[0-9]+/', '', strtolower(trim((string) ($criteria[0] ?? ''))));

        $family = ['objective' => $objective, 'files_shape' => $filesShape, 'accept_pattern' => $acceptPattern];
        $hash = hash('sha256', (string) json_encode($family, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return [
            'hash' => $hash,
            'normalized_objective' => $objective,
            'allowed_files_shape' => $filesShape,
            'acceptance_pattern' => $acceptPattern,
        ];
    }

    /**
     * High-level entry point: runs the 3-voter {@see AtlasLoopRefusalCriticPanel} over $taskContext and
     * merges every panel vote into the unified verdict's reasons[] (NO vote is silently dropped). Direct
     * service-detected signals can be appended via $extraFacts. When $previousFingerprints is supplied,
     * appends a farm refusal fact if the current task's fingerprint repeats >= $farmThreshold times.
     *
     * @param  array<string,mixed>  $taskContext
     * @param  list<array<string,mixed>>  $extraFacts  optional direct service-detected refusal facts
     * @param  list<array{hash:string,...}>  $previousFingerprints  prior templateFingerprint() results
     */
    public static function evaluate(array $taskContext, array $extraFacts = [], array $previousFingerprints = [], int $farmThreshold = 3): AtlasLoopAntiGoodhartRefusalVerdict
    {
        $panel = app(AtlasLoopRefusalCriticPanel::class)->deliberate($taskContext);

        $candidate = [];
        foreach (($panel['votes'] ?? []) as $vote) {
            $source = self::SOURCE_PROXY;
            if (in_array($vote['pattern_id'] ?? '', ['characterization-test-farm', 'self-edit-in-own-judge'], true)) {
                $source = self::SOURCE_FARM;
            }
            $severity = match ($vote['severity'] ?? '') {
                AtlasLoopRefusalCriticPanel::SEVERITY_BLOCK => self::SEVERITY_CRITICAL,
                AtlasLoopRefusalCriticPanel::SEVERITY_REFUSE => self::SEVERITY_HIGH,
                default => self::SEVERITY_LOW, // allow-votes still recorded as evidence; severity LOW
            };
            $candidate[] = [
                'source' => $source,
                'pattern_id' => (string) $vote['pattern_id'],
                'fact' => array_merge(
                    is_array($vote['fact'] ?? null) ? $vote['fact'] : [],
                    ['voter_fqn' => (string) $vote['voter_fqn'], 'panel_refuse' => (bool) $vote['refuse']],
                ),
                'severity' => $severity,
                'evidence_refs' => [],
            ];
        }

        if ($previousFingerprints !== []) {
            $fp = self::templateFingerprint($taskContext);
            $matchCount = count(array_filter($previousFingerprints, static fn (array $prev): bool => ($prev['hash'] ?? '') === $fp['hash']));
            if ($matchCount >= $farmThreshold) {
                $candidate[] = [
                    'source' => self::SOURCE_FARM,
                    'pattern_id' => 'template-farm-repeat',
                    'fact' => [
                        'family_hash' => $fp['hash'],
                        'repeat_count' => $matchCount,
                        'threshold' => $farmThreshold,
                        'normalized_objective' => $fp['normalized_objective'],
                    ],
                    'severity' => self::SEVERITY_HIGH,
                    'evidence_refs' => ['template_fingerprint', 'previous_fingerprints'],
                ];
            }
        }

        foreach ($extraFacts as $extra) {
            $candidate[] = is_array($extra) ? $extra : [];
        }

        return self::verdict($candidate);
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

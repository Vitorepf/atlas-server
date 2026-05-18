<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ResearchFinding;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ResearchOpenQuestion;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ResearchSource;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Canonical `atlas.dev.research_receipt.v1`.
 *
 * Atlas Dev Research Intelligence emits ONE receipt per research question.
 * The receipt is honesty-first:
 *
 *  - `status=answered` requires findings[] non-empty;
 *  - `status=blocked_insufficient_context` requires blocker_reasons[] non-empty;
 *  - `status=partial` requires open_questions[] non-empty AND findings[] non-empty;
 *  - `status=no_canonical_source` is the explicit "we looked, the docs don't
 *    say" state — requires sources[] non-empty (the consulted set) AND
 *    open_questions[] non-empty.
 *
 * Every finding cites at least one source by index, so a downstream reader
 * can re-trace the claim chain.
 *
 * The receipt does NOT invoke a provider. It is the planner-level artifact
 * that a future provider-backed Dev Research orchestrator can populate; for
 * the E2E scenario battery, it is filled in via planner heuristics + curated
 * canonical sources only.
 */
final class ResearchReceipt implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.research_receipt.v1';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_NO_CANONICAL_SOURCE = 'no_canonical_source';

    public const STATUS_BLOCKED_INSUFFICIENT_CONTEXT = 'blocked_insufficient_context';

    public const ALLOWED_STATUSES = [
        self::STATUS_ANSWERED,
        self::STATUS_PARTIAL,
        self::STATUS_NO_CANONICAL_SOURCE,
        self::STATUS_BLOCKED_INSUFFICIENT_CONTEXT,
    ];

    private const HASH_FIELD = 'receipt_hash';

    /**
     * @param  list<ResearchSource>  $sources
     * @param  list<ResearchFinding>  $findings
     * @param  list<ResearchOpenQuestion>  $openQuestions
     * @param  list<string>  $evidenceRefs
     * @param  list<string>  $blockerReasons
     */
    public function __construct(
        public readonly string $receiptId,
        public readonly string $runId,
        public readonly string $question,
        public readonly string $status,
        public readonly array $sources,
        public readonly array $findings,
        public readonly array $openQuestions,
        public readonly float $confidence,
        public readonly array $evidenceRefs,
        public readonly array $blockerReasons,
        public readonly string $createdAt,
        public readonly string $receiptHash,
    ) {
        if (trim($this->receiptId) === '') {
            throw new InvalidArgumentException('ResearchReceipt.receipt_id must not be empty.');
        }
        if (trim($this->runId) === '') {
            throw new InvalidArgumentException('ResearchReceipt.run_id must not be empty.');
        }
        if (trim($this->question) === '') {
            throw new InvalidArgumentException('ResearchReceipt.question must not be empty.');
        }
        if (! in_array($this->status, self::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                'ResearchReceipt.status must be one of ['.implode(',', self::ALLOWED_STATUSES)."], got '{$this->status}'."
            );
        }
        if ($this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new InvalidArgumentException(
                "ResearchReceipt.confidence must be in [0.0, 1.0], got {$this->confidence}."
            );
        }
        foreach ($this->sources as $i => $s) {
            if (! $s instanceof ResearchSource) {
                throw new InvalidArgumentException("ResearchReceipt.sources[{$i}] must be a ResearchSource.");
            }
        }
        foreach ($this->findings as $i => $f) {
            if (! $f instanceof ResearchFinding) {
                throw new InvalidArgumentException("ResearchReceipt.findings[{$i}] must be a ResearchFinding.");
            }
            foreach ($f->supports as $sIdx) {
                if ($sIdx < 0 || $sIdx >= count($this->sources)) {
                    throw new InvalidArgumentException(
                        "ResearchReceipt invariant: finding '{$f->findingId}' supports[]={$sIdx} is out of bounds; sources count="
                            .count($this->sources).'.'
                    );
                }
            }
        }
        foreach ($this->openQuestions as $i => $q) {
            if (! $q instanceof ResearchOpenQuestion) {
                throw new InvalidArgumentException("ResearchReceipt.open_questions[{$i}] must be a ResearchOpenQuestion.");
            }
        }
        foreach ($this->evidenceRefs as $i => $r) {
            if (! is_string($r) || trim($r) === '') {
                throw new InvalidArgumentException("ResearchReceipt.evidence_refs[{$i}] must be a non-empty string.");
            }
        }
        foreach ($this->blockerReasons as $i => $r) {
            if (! is_string($r) || trim($r) === '') {
                throw new InvalidArgumentException("ResearchReceipt.blocker_reasons[{$i}] must be a non-empty string.");
            }
        }
        if (trim($this->createdAt) === '') {
            throw new InvalidArgumentException('ResearchReceipt.created_at must not be empty.');
        }
        if (trim($this->receiptHash) === '') {
            throw new InvalidArgumentException('ResearchReceipt.receipt_hash must not be empty.');
        }

        // Invariants -------------------------------------------------------
        if ($this->status === self::STATUS_ANSWERED && $this->findings === []) {
            throw new InvalidArgumentException('ResearchReceipt invariant: status=answered requires findings[] non-empty.');
        }
        if ($this->status === self::STATUS_PARTIAL && ($this->findings === [] || $this->openQuestions === [])) {
            throw new InvalidArgumentException(
                'ResearchReceipt invariant: status=partial requires findings[] AND open_questions[] non-empty.'
            );
        }
        if ($this->status === self::STATUS_NO_CANONICAL_SOURCE) {
            if ($this->sources === []) {
                throw new InvalidArgumentException(
                    'ResearchReceipt invariant: status=no_canonical_source requires sources[] non-empty (what was consulted).'
                );
            }
            if ($this->openQuestions === []) {
                throw new InvalidArgumentException(
                    'ResearchReceipt invariant: status=no_canonical_source requires open_questions[] non-empty.'
                );
            }
        }
        if ($this->status === self::STATUS_BLOCKED_INSUFFICIENT_CONTEXT && $this->blockerReasons === []) {
            throw new InvalidArgumentException(
                'ResearchReceipt invariant: status=blocked_insufficient_context requires blocker_reasons[] non-empty.'
            );
        }
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'blocker_reasons' => array_values($this->blockerReasons),
            'confidence' => $this->confidence,
            'created_at' => $this->createdAt,
            'evidence_refs' => array_values($this->evidenceRefs),
            'findings' => array_map(
                static fn (ResearchFinding $f): array => $f->toCanonicalArray(),
                $this->findings,
            ),
            'findings_count' => count($this->findings),
            'open_questions' => array_map(
                static fn (ResearchOpenQuestion $q): array => $q->toCanonicalArray(),
                $this->openQuestions,
            ),
            'open_questions_count' => count($this->openQuestions),
            'provider_safe' => true,
            'question' => $this->question,
            'receipt_hash' => $this->receiptHash,
            'receipt_id' => $this->receiptId,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'sources' => array_map(
                static fn (ResearchSource $s): array => $s->toCanonicalArray(),
                $this->sources,
            ),
            'sources_count' => count($this->sources),
            'status' => $this->status,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        return (string) json_encode(
            $this->toCanonicalArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    public function hash(): string
    {
        $payload = $this->toCanonicalArray();
        unset($payload[self::HASH_FIELD]);

        return CanonicalHasher::hash($payload);
    }

    public function isProviderSafe(): bool
    {
        return true;
    }

    /**
     * @param  list<ResearchSource>  $sources
     * @param  list<ResearchFinding>  $findings
     * @param  list<ResearchOpenQuestion>  $openQuestions
     * @param  list<string>  $evidenceRefs
     * @param  list<string>  $blockerReasons
     */
    public static function issue(
        string $receiptId,
        string $runId,
        string $question,
        string $status,
        array $sources,
        array $findings,
        array $openQuestions,
        float $confidence,
        array $evidenceRefs,
        array $blockerReasons,
        string $createdAt,
    ): self {
        $skeleton = new self(
            receiptId: $receiptId,
            runId: $runId,
            question: $question,
            status: $status,
            sources: $sources,
            findings: $findings,
            openQuestions: $openQuestions,
            confidence: $confidence,
            evidenceRefs: $evidenceRefs,
            blockerReasons: $blockerReasons,
            createdAt: $createdAt,
            receiptHash: 'pending',
        );
        $hash = $skeleton->hash();

        return new self(
            receiptId: $receiptId,
            runId: $runId,
            question: $question,
            status: $status,
            sources: $sources,
            findings: $findings,
            openQuestions: $openQuestions,
            confidence: $confidence,
            evidenceRefs: $evidenceRefs,
            blockerReasons: $blockerReasons,
            createdAt: $createdAt,
            receiptHash: $hash,
        );
    }

    public static function fromArray(array $payload): self
    {
        if (! array_key_exists('confidence', $payload) || ! is_numeric($payload['confidence'])) {
            throw new InvalidArgumentException("Field 'confidence' must be numeric.");
        }

        $sources = [];
        foreach ((array) ($payload['sources'] ?? []) as $s) {
            if (! is_array($s)) {
                throw new InvalidArgumentException('ResearchReceipt.sources entries must be arrays.');
            }
            $sources[] = ResearchSource::fromArray($s);
        }

        $findings = [];
        foreach ((array) ($payload['findings'] ?? []) as $f) {
            if (! is_array($f)) {
                throw new InvalidArgumentException('ResearchReceipt.findings entries must be arrays.');
            }
            $findings[] = ResearchFinding::fromArray($f);
        }

        $openQuestions = [];
        foreach ((array) ($payload['open_questions'] ?? []) as $q) {
            if (! is_array($q)) {
                throw new InvalidArgumentException('ResearchReceipt.open_questions entries must be arrays.');
            }
            $openQuestions[] = ResearchOpenQuestion::fromArray($q);
        }

        return new self(
            receiptId: AtlasDevSchemaArray::string($payload, 'receipt_id'),
            runId: AtlasDevSchemaArray::string($payload, 'run_id'),
            question: AtlasDevSchemaArray::string($payload, 'question'),
            status: AtlasDevSchemaArray::string($payload, 'status'),
            sources: $sources,
            findings: $findings,
            openQuestions: $openQuestions,
            confidence: (float) $payload['confidence'],
            evidenceRefs: AtlasDevSchemaArray::stringList($payload, 'evidence_refs'),
            blockerReasons: AtlasDevSchemaArray::stringList($payload, 'blocker_reasons'),
            createdAt: AtlasDevSchemaArray::string($payload, 'created_at'),
            receiptHash: AtlasDevSchemaArray::string($payload, 'receipt_hash'),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Single actionable finding emitted by Atlas Dev Review Intelligence.
 *
 * A finding is the bug/risk/gap a reviewer would surface in a PR. It carries
 * severity + risk type + (file/line when available) + remediation + a
 * confidence score. The pair `risk_type` × `severity` is the canonical handle
 * the operator uses to prioritise — review_receipt sorts findings by
 * severity descending so the human always sees the worst risks first.
 */
final class ReviewFinding implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.review_finding.v1';

    public const SEVERITY_BLOCKER = 'blocker';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_INFO = 'info';

    public const ALLOWED_SEVERITIES = [
        self::SEVERITY_BLOCKER,
        self::SEVERITY_CRITICAL,
        self::SEVERITY_HIGH,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_LOW,
        self::SEVERITY_INFO,
    ];

    /** Severity precedence used by ReviewIntelligenceService when sorting. */
    public const SEVERITY_RANK = [
        self::SEVERITY_BLOCKER => 0,
        self::SEVERITY_CRITICAL => 1,
        self::SEVERITY_HIGH => 2,
        self::SEVERITY_MEDIUM => 3,
        self::SEVERITY_LOW => 4,
        self::SEVERITY_INFO => 5,
    ];

    /** Canonical risk taxonomy aligned with the agentic-rag professional spec. */
    public const RISK_BUG = 'bug';

    public const RISK_REGRESSION = 'regression';

    public const RISK_SECURITY = 'security';

    public const RISK_PERFORMANCE = 'performance';

    public const RISK_RELIABILITY = 'reliability';

    public const RISK_DATA_LOSS = 'data_loss';

    public const RISK_TEST_GAP = 'test_gap';

    public const RISK_DOC_GAP = 'doc_gap';

    public const RISK_DEPENDENCY = 'dependency';

    public const RISK_STYLE = 'style';

    public const RISK_SCOPE_VIOLATION = 'scope_violation';

    public const RISK_SECRET_LEAK = 'secret_leak';

    public const RISK_LICENSE = 'license';

    public const RISK_ARCHITECTURE_DRIFT = 'architecture_drift';

    public const RISK_OTHER = 'other';

    public const ALLOWED_RISK_TYPES = [
        self::RISK_BUG,
        self::RISK_REGRESSION,
        self::RISK_SECURITY,
        self::RISK_PERFORMANCE,
        self::RISK_RELIABILITY,
        self::RISK_DATA_LOSS,
        self::RISK_TEST_GAP,
        self::RISK_DOC_GAP,
        self::RISK_DEPENDENCY,
        self::RISK_STYLE,
        self::RISK_SCOPE_VIOLATION,
        self::RISK_SECRET_LEAK,
        self::RISK_LICENSE,
        self::RISK_ARCHITECTURE_DRIFT,
        self::RISK_OTHER,
    ];

    /**
     * @param  list<string>  $evidenceRefKinds
     */
    public function __construct(
        public readonly string $findingId,
        public readonly string $title,
        public readonly string $severity,
        public readonly string $riskType,
        public readonly string $description,
        public readonly string $remediation,
        public readonly float $confidence,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
        public readonly ?int $lineEnd = null,
        public readonly ?string $symbol = null,
        public readonly bool $testGap = false,
        public readonly array $evidenceRefKinds = [],
    ) {
        if (trim($this->findingId) === '') {
            throw new InvalidArgumentException('ReviewFinding.finding_id must not be empty.');
        }
        if (trim($this->title) === '') {
            throw new InvalidArgumentException('ReviewFinding.title must not be empty.');
        }
        if (! in_array($this->severity, self::ALLOWED_SEVERITIES, true)) {
            throw new InvalidArgumentException(
                'ReviewFinding.severity must be one of ['.implode(',', self::ALLOWED_SEVERITIES)."], got '{$this->severity}'."
            );
        }
        if (! in_array($this->riskType, self::ALLOWED_RISK_TYPES, true)) {
            throw new InvalidArgumentException(
                'ReviewFinding.risk_type must be one of ['.implode(',', self::ALLOWED_RISK_TYPES)."], got '{$this->riskType}'."
            );
        }
        if (trim($this->description) === '') {
            throw new InvalidArgumentException('ReviewFinding.description must not be empty.');
        }
        if (trim($this->remediation) === '') {
            throw new InvalidArgumentException('ReviewFinding.remediation must not be empty.');
        }
        if ($this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new InvalidArgumentException(
                "ReviewFinding.confidence must be in [0.0, 1.0], got {$this->confidence}."
            );
        }
        if ($this->line !== null && $this->line < 1) {
            throw new InvalidArgumentException('ReviewFinding.line must be >= 1 when provided.');
        }
        if ($this->lineEnd !== null) {
            if ($this->lineEnd < 1) {
                throw new InvalidArgumentException('ReviewFinding.line_end must be >= 1 when provided.');
            }
            if ($this->line !== null && $this->lineEnd < $this->line) {
                throw new InvalidArgumentException('ReviewFinding.line_end must be >= line when both provided.');
            }
        }
        // Invariant: blocker/critical severity findings must have a remediation
        // that is more than a placeholder so the operator has something to act on.
        if (in_array($this->severity, [self::SEVERITY_BLOCKER, self::SEVERITY_CRITICAL], true)
            && mb_strlen($this->remediation) < 8
        ) {
            throw new InvalidArgumentException(
                "ReviewFinding invariant: severity='{$this->severity}' requires a concrete remediation (>= 8 chars)."
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
            'confidence' => $this->confidence,
            'description' => $this->description,
            'evidence_ref_kinds' => array_values($this->evidenceRefKinds),
            'file' => $this->file,
            'finding_id' => $this->findingId,
            'line' => $this->line,
            'line_end' => $this->lineEnd,
            'remediation' => $this->remediation,
            'risk_type' => $this->riskType,
            'severity' => $this->severity,
            'symbol' => $this->symbol,
            'test_gap' => $this->testGap,
            'title' => $this->title,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    public function hash(): string
    {
        return CanonicalHasher::hash($this->toCanonicalArray());
    }

    public function isProviderSafe(): bool
    {
        return true;
    }

    public function severityRank(): int
    {
        return self::SEVERITY_RANK[$this->severity] ?? PHP_INT_MAX;
    }

    public static function fromArray(array $payload): self
    {
        if (! array_key_exists('confidence', $payload) || ! is_numeric($payload['confidence'])) {
            throw new InvalidArgumentException("Field 'confidence' must be numeric.");
        }

        return new self(
            findingId: AtlasDevSchemaArray::string($payload, 'finding_id'),
            title: AtlasDevSchemaArray::string($payload, 'title'),
            severity: AtlasDevSchemaArray::string($payload, 'severity'),
            riskType: AtlasDevSchemaArray::string($payload, 'risk_type'),
            description: AtlasDevSchemaArray::string($payload, 'description'),
            remediation: AtlasDevSchemaArray::string($payload, 'remediation'),
            confidence: (float) $payload['confidence'],
            file: AtlasDevSchemaArray::nullableString($payload, 'file'),
            line: AtlasDevSchemaArray::nullableInt($payload, 'line'),
            lineEnd: AtlasDevSchemaArray::nullableInt($payload, 'line_end'),
            symbol: AtlasDevSchemaArray::nullableString($payload, 'symbol'),
            testGap: array_key_exists('test_gap', $payload) ? AtlasDevSchemaArray::bool($payload, 'test_gap') : false,
            evidenceRefKinds: AtlasDevSchemaArray::stringList($payload, 'evidence_ref_kinds'),
        );
    }
}

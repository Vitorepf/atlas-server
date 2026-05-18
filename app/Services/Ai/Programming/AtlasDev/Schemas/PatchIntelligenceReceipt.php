<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\BlastRadius;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EvidenceRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopePreExistingChange;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Patch Intelligence receipt — Atlas Dev's structured report on what a patch
 * did, where it landed, how risky it looks and what to do if it goes wrong.
 *
 * Filled by PatchIntelligenceService after PatchApplier has produced the
 * ScopeFileDiff list. Lives next to ScopeGuardReceipt in the per-run receipts
 * directory and is hashed for tamper-evidence.
 */
final class PatchIntelligenceReceipt implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.patch_intelligence_receipt.v1';

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    public const RISK_CRITICAL = 'critical';

    public const ALLOWED_RISK_LEVELS = [
        self::RISK_LOW,
        self::RISK_MEDIUM,
        self::RISK_HIGH,
        self::RISK_CRITICAL,
    ];

    private const HASH_FIELD = 'receipt_hash';

    /**
     * @param  list<string>  $expectedFiles
     * @param  list<ScopeFileDiff>  $changedFiles
     * @param  list<string>  $unexpectedFiles
     * @param  list<string>  $missingExpectedFiles
     * @param  list<string>  $localPatternNotes
     * @param  list<string>  $userChangePreservationNotes
     * @param  list<ScopePreExistingChange>  $userPreExistingChanges
     * @param  list<EvidenceRef>  $evidenceRefs
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly array $expectedFiles,
        public readonly array $changedFiles,
        public readonly array $unexpectedFiles,
        public readonly array $missingExpectedFiles,
        public readonly string $riskLevel,
        public readonly BlastRadius $blastRadius,
        public readonly array $localPatternNotes,
        public readonly array $userChangePreservationNotes,
        public readonly array $userPreExistingChanges,
        public readonly string $rollbackHint,
        public readonly array $evidenceRefs,
        public readonly string $receiptHash,
        public readonly bool $providerSafe = true,
    ) {
        if ($this->runId === '') {
            throw new InvalidArgumentException('PatchIntelligenceReceipt.run_id must not be empty.');
        }
        if ($this->taskContractHash === '') {
            throw new InvalidArgumentException('PatchIntelligenceReceipt.task_contract_hash must not be empty.');
        }
        if (! in_array($this->riskLevel, self::ALLOWED_RISK_LEVELS, true)) {
            throw new InvalidArgumentException(
                'PatchIntelligenceReceipt.risk_level must be one of ['.implode(',', self::ALLOWED_RISK_LEVELS)."], got '{$this->riskLevel}'."
            );
        }
        if ($this->rollbackHint === '') {
            throw new InvalidArgumentException('PatchIntelligenceReceipt.rollback_hint must not be empty.');
        }
        $this->assertStringList($this->expectedFiles, 'expected_files');
        $this->assertStringList($this->unexpectedFiles, 'unexpected_files');
        $this->assertStringList($this->missingExpectedFiles, 'missing_expected_files');
        $this->assertStringList($this->localPatternNotes, 'local_pattern_notes');
        $this->assertStringList($this->userChangePreservationNotes, 'user_change_preservation_notes');
        foreach ($this->changedFiles as $i => $diff) {
            if (! $diff instanceof ScopeFileDiff) {
                throw new InvalidArgumentException("changed_files[{$i}] must be ScopeFileDiff.");
            }
        }
        foreach ($this->userPreExistingChanges as $i => $change) {
            if (! $change instanceof ScopePreExistingChange) {
                throw new InvalidArgumentException("user_pre_existing_changes[{$i}] must be ScopePreExistingChange.");
            }
        }
        foreach ($this->evidenceRefs as $i => $ref) {
            if (! $ref instanceof EvidenceRef) {
                throw new InvalidArgumentException("evidence_refs[{$i}] must be EvidenceRef.");
            }
        }
        if ($this->riskLevel === self::RISK_CRITICAL && $this->evidenceRefs === []) {
            throw new InvalidArgumentException('PatchIntelligenceReceipt.evidence_refs must not be empty when risk_level is critical.');
        }
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'blast_radius' => $this->blastRadius->toCanonicalArray(),
            'changed_files' => array_map(
                static fn (ScopeFileDiff $d): array => $d->toCanonicalArray(),
                array_values($this->changedFiles),
            ),
            'evidence_refs' => array_map(
                static fn (EvidenceRef $e): array => $e->toCanonicalArray(),
                array_values($this->evidenceRefs),
            ),
            'expected_files' => $this->expectedFiles,
            'local_pattern_notes' => $this->localPatternNotes,
            'missing_expected_files' => $this->missingExpectedFiles,
            'receipt_hash' => $this->receiptHash,
            'risk_level' => $this->riskLevel,
            'rollback_hint' => $this->rollbackHint,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'task_contract_hash' => $this->taskContractHash,
            'unexpected_files' => $this->unexpectedFiles,
            'user_change_preservation_notes' => $this->userChangePreservationNotes,
            'user_pre_existing_changes' => array_map(
                static fn (ScopePreExistingChange $c): array => $c->toCanonicalArray(),
                array_values($this->userPreExistingChanges),
            ),
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
        return CanonicalHasher::hashWithout($this->toCanonicalArray(), self::HASH_FIELD);
    }

    public function isProviderSafe(): bool
    {
        return $this->providerSafe;
    }

    /**
     * Construct and seal: returns a DTO whose receipt_hash is the canonical
     * hash of every other field. Mirrors ScopeGuardReceipt::issue().
     *
     * @param  list<string>  $expectedFiles
     * @param  list<ScopeFileDiff>  $changedFiles
     * @param  list<string>  $unexpectedFiles
     * @param  list<string>  $missingExpectedFiles
     * @param  list<string>  $localPatternNotes
     * @param  list<string>  $userChangePreservationNotes
     * @param  list<ScopePreExistingChange>  $userPreExistingChanges
     * @param  list<EvidenceRef>  $evidenceRefs
     */
    public static function issue(
        string $runId,
        string $taskContractHash,
        array $expectedFiles,
        array $changedFiles,
        array $unexpectedFiles,
        array $missingExpectedFiles,
        string $riskLevel,
        BlastRadius $blastRadius,
        array $localPatternNotes,
        array $userChangePreservationNotes,
        array $userPreExistingChanges,
        string $rollbackHint,
        array $evidenceRefs,
    ): self {
        $skeleton = new self(
            runId: $runId,
            taskContractHash: $taskContractHash,
            expectedFiles: $expectedFiles,
            changedFiles: $changedFiles,
            unexpectedFiles: $unexpectedFiles,
            missingExpectedFiles: $missingExpectedFiles,
            riskLevel: $riskLevel,
            blastRadius: $blastRadius,
            localPatternNotes: $localPatternNotes,
            userChangePreservationNotes: $userChangePreservationNotes,
            userPreExistingChanges: $userPreExistingChanges,
            rollbackHint: $rollbackHint,
            evidenceRefs: $evidenceRefs,
            receiptHash: 'pending',
        );
        $hash = $skeleton->hash();

        return new self(
            runId: $runId,
            taskContractHash: $taskContractHash,
            expectedFiles: $expectedFiles,
            changedFiles: $changedFiles,
            unexpectedFiles: $unexpectedFiles,
            missingExpectedFiles: $missingExpectedFiles,
            riskLevel: $riskLevel,
            blastRadius: $blastRadius,
            localPatternNotes: $localPatternNotes,
            userChangePreservationNotes: $userChangePreservationNotes,
            userPreExistingChanges: $userPreExistingChanges,
            rollbackHint: $rollbackHint,
            evidenceRefs: $evidenceRefs,
            receiptHash: $hash,
        );
    }

    public static function fromArray(array $payload): self
    {
        $expectedFiles = self::asStringList((array) ($payload['expected_files'] ?? []), 'expected_files');
        $unexpected = self::asStringList((array) ($payload['unexpected_files'] ?? []), 'unexpected_files');
        $missing = self::asStringList((array) ($payload['missing_expected_files'] ?? []), 'missing_expected_files');
        $patternNotes = self::asStringList((array) ($payload['local_pattern_notes'] ?? []), 'local_pattern_notes');
        $userNotes = self::asStringList((array) ($payload['user_change_preservation_notes'] ?? []), 'user_change_preservation_notes');

        $changed = [];
        foreach (array_values((array) ($payload['changed_files'] ?? [])) as $i => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException("changed_files[{$i}] must be an array.");
            }
            $changed[] = ScopeFileDiff::fromArray($raw);
        }

        $userChanges = [];
        foreach (array_values((array) ($payload['user_pre_existing_changes'] ?? [])) as $i => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException("user_pre_existing_changes[{$i}] must be an array.");
            }
            $userChanges[] = ScopePreExistingChange::fromArray($raw);
        }

        $refs = [];
        foreach (array_values((array) ($payload['evidence_refs'] ?? [])) as $i => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException("evidence_refs[{$i}] must be an array.");
            }
            $refs[] = EvidenceRef::fromArray($raw);
        }

        $blastRaw = (array) ($payload['blast_radius'] ?? []);
        $blast = BlastRadius::fromArray($blastRaw);

        return new self(
            runId: AtlasDevSchemaArray::string($payload, 'run_id'),
            taskContractHash: AtlasDevSchemaArray::string($payload, 'task_contract_hash'),
            expectedFiles: $expectedFiles,
            changedFiles: $changed,
            unexpectedFiles: $unexpected,
            missingExpectedFiles: $missing,
            riskLevel: AtlasDevSchemaArray::string($payload, 'risk_level'),
            blastRadius: $blast,
            localPatternNotes: $patternNotes,
            userChangePreservationNotes: $userNotes,
            userPreExistingChanges: $userChanges,
            rollbackHint: AtlasDevSchemaArray::string($payload, 'rollback_hint'),
            evidenceRefs: $refs,
            receiptHash: AtlasDevSchemaArray::string($payload, 'receipt_hash'),
        );
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private static function asStringList(array $values, string $field): array
    {
        $out = [];
        foreach (array_values($values) as $i => $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException("{$field}[{$i}] must be a string.");
            }
            $out[] = $value;
        }

        return $out;
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function assertStringList(array $values, string $field): void
    {
        foreach ($values as $i => $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException("PatchIntelligenceReceipt.{$field}[{$i}] must be a string.");
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EvidenceRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\FocusedTestCommand;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Test Selection receipt — Atlas Dev's record of what tests should run given
 * a patch, what was skipped and why. Produced by
 * TestSelectionIntelligenceService and persisted as test_selection_receipt.json.
 *
 * Selection is intentionally NOT "run the entire suite": that would defeat
 * the point of having intelligence. When no focused tests can be derived, a
 * non-empty skipped_tests_reason is required so callers can decide whether
 * to escalate or accept "no test reason".
 */
final class TestSelectionReceipt implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.test_selection_receipt.v1';

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_HIGH = 'high';

    public const ALLOWED_CONFIDENCES = [
        self::CONFIDENCE_LOW,
        self::CONFIDENCE_MEDIUM,
        self::CONFIDENCE_HIGH,
    ];

    private const HASH_FIELD = 'receipt_hash';

    /**
     * @param  list<string>  $expectedTests
     * @param  list<FocusedTestCommand>  $focusedTestCommands
     * @param  list<EvidenceRef>  $evidenceRefs
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly array $expectedTests,
        public readonly array $focusedTestCommands,
        public readonly ?string $skippedTestsReason,
        public readonly string $overallConfidence,
        public readonly array $evidenceRefs,
        public readonly string $receiptHash,
        public readonly bool $providerSafe = true,
    ) {
        if ($this->runId === '') {
            throw new InvalidArgumentException('TestSelectionReceipt.run_id must not be empty.');
        }
        if ($this->taskContractHash === '') {
            throw new InvalidArgumentException('TestSelectionReceipt.task_contract_hash must not be empty.');
        }
        if (! in_array($this->overallConfidence, self::ALLOWED_CONFIDENCES, true)) {
            throw new InvalidArgumentException(
                'TestSelectionReceipt.overall_confidence must be one of ['.implode(',', self::ALLOWED_CONFIDENCES)."], got '{$this->overallConfidence}'."
            );
        }
        foreach ($this->expectedTests as $i => $value) {
            if (! is_string($value) || $value === '') {
                throw new InvalidArgumentException("expected_tests[{$i}] must be a non-empty string.");
            }
        }
        foreach ($this->focusedTestCommands as $i => $cmd) {
            if (! $cmd instanceof FocusedTestCommand) {
                throw new InvalidArgumentException("focused_test_commands[{$i}] must be FocusedTestCommand.");
            }
        }
        foreach ($this->evidenceRefs as $i => $ref) {
            if (! $ref instanceof EvidenceRef) {
                throw new InvalidArgumentException("evidence_refs[{$i}] must be EvidenceRef.");
            }
        }
        if ($this->focusedTestCommands === []
            && ($this->skippedTestsReason === null || trim($this->skippedTestsReason) === '')) {
            throw new InvalidArgumentException(
                'TestSelectionReceipt requires skipped_tests_reason when focused_test_commands is empty.'
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
            'evidence_refs' => array_map(
                static fn (EvidenceRef $e): array => $e->toCanonicalArray(),
                array_values($this->evidenceRefs),
            ),
            'expected_tests' => $this->expectedTests,
            'focused_test_commands' => array_map(
                static fn (FocusedTestCommand $c): array => $c->toCanonicalArray(),
                array_values($this->focusedTestCommands),
            ),
            'overall_confidence' => $this->overallConfidence,
            'receipt_hash' => $this->receiptHash,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'skipped_tests_reason' => $this->skippedTestsReason,
            'task_contract_hash' => $this->taskContractHash,
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
     * @param  list<string>  $expectedTests
     * @param  list<FocusedTestCommand>  $focusedTestCommands
     * @param  list<EvidenceRef>  $evidenceRefs
     */
    public static function issue(
        string $runId,
        string $taskContractHash,
        array $expectedTests,
        array $focusedTestCommands,
        ?string $skippedTestsReason,
        string $overallConfidence,
        array $evidenceRefs,
    ): self {
        $skeleton = new self(
            runId: $runId,
            taskContractHash: $taskContractHash,
            expectedTests: $expectedTests,
            focusedTestCommands: $focusedTestCommands,
            skippedTestsReason: $skippedTestsReason,
            overallConfidence: $overallConfidence,
            evidenceRefs: $evidenceRefs,
            receiptHash: 'pending',
        );
        $hash = $skeleton->hash();

        return new self(
            runId: $runId,
            taskContractHash: $taskContractHash,
            expectedTests: $expectedTests,
            focusedTestCommands: $focusedTestCommands,
            skippedTestsReason: $skippedTestsReason,
            overallConfidence: $overallConfidence,
            evidenceRefs: $evidenceRefs,
            receiptHash: $hash,
        );
    }

    public static function fromArray(array $payload): self
    {
        $expected = [];
        foreach (array_values((array) ($payload['expected_tests'] ?? [])) as $i => $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException("expected_tests[{$i}] must be a string.");
            }
            $expected[] = $value;
        }

        $focused = [];
        foreach (array_values((array) ($payload['focused_test_commands'] ?? [])) as $i => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException("focused_test_commands[{$i}] must be an array.");
            }
            $focused[] = FocusedTestCommand::fromArray($raw);
        }

        $refs = [];
        foreach (array_values((array) ($payload['evidence_refs'] ?? [])) as $i => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException("evidence_refs[{$i}] must be an array.");
            }
            $refs[] = EvidenceRef::fromArray($raw);
        }

        return new self(
            runId: AtlasDevSchemaArray::string($payload, 'run_id'),
            taskContractHash: AtlasDevSchemaArray::string($payload, 'task_contract_hash'),
            expectedTests: $expected,
            focusedTestCommands: $focused,
            skippedTestsReason: AtlasDevSchemaArray::nullableString($payload, 'skipped_tests_reason'),
            overallConfidence: AtlasDevSchemaArray::string($payload, 'overall_confidence'),
            evidenceRefs: $refs,
            receiptHash: AtlasDevSchemaArray::string($payload, 'receipt_hash'),
        );
    }
}

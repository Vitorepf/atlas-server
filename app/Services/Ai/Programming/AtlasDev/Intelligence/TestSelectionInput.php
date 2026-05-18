<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EvidenceRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\PatchIntelligenceReceipt;
use InvalidArgumentException;

/**
 * Input bundle for TestSelectionIntelligenceService::select(). Decouples the
 * service from the rest of the orchestrator: callers wire only what they
 * already have.
 */
final class TestSelectionInput
{
    /**
     * @param  list<string>  $changedFiles
     *                                      Production + test file paths produced by the patch.
     * @param  list<string>  $expectedTests
     *                                       Optional list of test files/IDs the spec or task contract expected
     *                                       to run. When empty, the service derives the set itself.
     * @param  list<EvidenceRef>  $evidenceRefs
     * @param  ?callable(string):bool  $fileExists
     *                                              Optional probe so tests can stub filesystem checks without touching
     *                                              the disk. Defaults to is_file() under base_path() when not provided.
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly array $changedFiles = [],
        public readonly array $expectedTests = [],
        public readonly string $riskLevel = PatchIntelligenceReceipt::RISK_LOW,
        public readonly array $evidenceRefs = [],
        public readonly mixed $fileExists = null,
    ) {
        if ($this->runId === '') {
            throw new InvalidArgumentException('TestSelectionInput.run_id must not be empty.');
        }
        if ($this->taskContractHash === '') {
            throw new InvalidArgumentException('TestSelectionInput.task_contract_hash must not be empty.');
        }
        if (! in_array($this->riskLevel, PatchIntelligenceReceipt::ALLOWED_RISK_LEVELS, true)) {
            throw new InvalidArgumentException(
                'TestSelectionInput.risk_level must be one of ['.implode(',', PatchIntelligenceReceipt::ALLOWED_RISK_LEVELS)."], got '{$this->riskLevel}'."
            );
        }
        if ($this->fileExists !== null && ! is_callable($this->fileExists)) {
            throw new InvalidArgumentException('TestSelectionInput.fileExists must be callable when provided.');
        }
        foreach ($this->changedFiles as $i => $file) {
            if (! is_string($file) || $file === '') {
                throw new InvalidArgumentException("changed_files[{$i}] must be a non-empty string.");
            }
        }
        foreach ($this->expectedTests as $i => $test) {
            if (! is_string($test) || $test === '') {
                throw new InvalidArgumentException("expected_tests[{$i}] must be a non-empty string.");
            }
        }
        foreach ($this->evidenceRefs as $i => $ref) {
            if (! $ref instanceof EvidenceRef) {
                throw new InvalidArgumentException("evidence_refs[{$i}] must be EvidenceRef.");
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EvidenceRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopePreExistingChange;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use InvalidArgumentException;

/**
 * Input bundle for PatchIntelligenceService::analyze().
 *
 * Keeps the service signature small and lets callers (orchestrator,
 * standalone CLI, repair loop) construct the input from whichever sources
 * they already have on hand — the ScopeFileDiff/ScopePreExistingChange
 * arrays produced by PatchApplier or any equivalent surface.
 */
final class PatchIntelligenceInput
{
    /**
     * @param  list<string>  $expectedFiles
     * @param  list<ScopeFileDiff>  $changedFiles
     * @param  list<ScopePreExistingChange>  $userPreExistingChanges
     * @param  list<EvidenceRef>  $evidenceRefs
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly array $expectedFiles = [],
        public readonly array $changedFiles = [],
        public readonly array $userPreExistingChanges = [],
        public readonly array $evidenceRefs = [],
    ) {
        if ($this->runId === '') {
            throw new InvalidArgumentException('PatchIntelligenceInput.run_id must not be empty.');
        }
        if ($this->taskContractHash === '') {
            throw new InvalidArgumentException('PatchIntelligenceInput.task_contract_hash must not be empty.');
        }
        AtlasDevStringListNormalizer::requireNonEmptyStrings($this->expectedFiles, 'expected_files');
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
    }
}

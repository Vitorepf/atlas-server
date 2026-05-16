<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\RepairPolicy;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class LightTaskContract implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.light_task_contract.v1';

    public const OWNER = 'atlas_dev_sonnet';

    private const HASH_FIELD = 'task_contract_hash';

    /**
     * @param  list<string>  $allowedTools
     * @param  list<string>  $blockedActions
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $watchedFiles
     * @param  list<string>  $forbiddenFiles
     * @param  list<string>  $validationCommands
     * @param  list<string>  $evidenceRequired
     * @param  list<string>  $escalationOn
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $taskId,
        public readonly string $specHash,
        public readonly array $allowedTools,
        public readonly array $blockedActions,
        public readonly array $allowedFiles,
        public readonly array $watchedFiles,
        public readonly array $forbiddenFiles,
        public readonly int $maxFilesChanged,
        public readonly array $validationCommands,
        public readonly array $evidenceRequired,
        public readonly RepairPolicy $repairPolicy,
        public readonly array $escalationOn,
        public readonly ProviderLock $providerLock,
        public readonly string $taskContractHash,
        public readonly ?string $noTestReason = null,
    ) {}

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'allowed_files' => array_values($this->allowedFiles),
            'allowed_tools' => array_values($this->allowedTools),
            'blocked_actions' => array_values($this->blockedActions),
            'escalation_on' => array_values($this->escalationOn),
            'evidence_required' => array_values($this->evidenceRequired),
            'forbidden_files' => array_values($this->forbiddenFiles),
            'max_files_changed' => $this->maxFilesChanged,
            'no_test_reason' => $this->noTestReason,
            'owner' => self::OWNER,
            'provider_lock' => $this->providerLock->toCanonicalArray(),
            'provider_safe' => true,
            'repair_policy' => $this->repairPolicy->toCanonicalArray(),
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'spec_hash' => $this->specHash,
            'task_contract_hash' => $this->taskContractHash,
            'task_id' => $this->taskId,
            'validation_commands' => array_values($this->validationCommands),
            'watched_files' => array_values($this->watchedFiles),
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
        return true;
    }

    public function allowsWrite(): bool
    {
        return in_array('write', $this->allowedTools, true);
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            runId: (string) $payload['run_id'],
            taskId: (string) $payload['task_id'],
            specHash: (string) $payload['spec_hash'],
            allowedTools: array_values((array) ($payload['allowed_tools'] ?? [])),
            blockedActions: array_values((array) ($payload['blocked_actions'] ?? [])),
            allowedFiles: array_values((array) ($payload['allowed_files'] ?? [])),
            watchedFiles: array_values((array) ($payload['watched_files'] ?? [])),
            forbiddenFiles: array_values((array) ($payload['forbidden_files'] ?? [])),
            maxFilesChanged: (int) $payload['max_files_changed'],
            validationCommands: array_values((array) ($payload['validation_commands'] ?? [])),
            evidenceRequired: array_values((array) ($payload['evidence_required'] ?? [])),
            repairPolicy: RepairPolicy::fromArray((array) $payload['repair_policy']),
            escalationOn: array_values((array) ($payload['escalation_on'] ?? [])),
            providerLock: ProviderLock::fromArray((array) $payload['provider_lock']),
            taskContractHash: (string) ($payload['task_contract_hash'] ?? ''),
            noTestReason: $payload['no_test_reason'] ?? null,
        );
    }
}

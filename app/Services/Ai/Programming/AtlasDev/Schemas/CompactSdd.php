<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextBudget;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class CompactSdd implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.compact_sdd.v1';

    private const HASH_FIELD = 'compact_sdd_hash';

    /**
     * Fields excluded from hash calculation per contracts doc 4.2 invariant 7:
     * the SDD hash identifies the classification decision, not the downstream
     * artifacts whose hashes are folded in later via monotonic with*() methods.
     *
     * @var list<string>
     */
    private const HASH_EXCLUDES = ['compact_sdd_hash', 'mini_spec_hash', 'task_contract_hash'];

    /**
     * @param  list<string>  $docTiersRequired
     * @param  list<string>  $escalationTriggers
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $envelopeHash,
        public readonly string $intentRaw,
        public readonly string $intentNormalized,
        public readonly string $taskKind,
        public readonly string $riskLevel,
        public readonly string $scopeMode,
        public readonly string $mode,
        public readonly ContextBudget $contextBudget,
        public readonly array $docTiersRequired,
        public readonly string $verificationProfile,
        public readonly ?string $contextDigest,
        public readonly array $escalationTriggers,
        public readonly ?string $miniSpecHash,
        public readonly ?string $taskContractHash,
        public readonly string $compactSddHash,
    ) {}

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'compact_sdd_hash' => $this->compactSddHash,
            'context_budget' => $this->contextBudget->toCanonicalArray(),
            'context_digest' => $this->contextDigest,
            'doc_tiers_required' => array_values($this->docTiersRequired),
            'envelope_hash' => $this->envelopeHash,
            'escalation_triggers' => array_values($this->escalationTriggers),
            'intent_normalized' => $this->intentNormalized,
            'intent_raw' => $this->intentRaw,
            'mini_spec_hash' => $this->miniSpecHash,
            'mode' => $this->mode,
            'provider_safe' => true,
            'risk_level' => $this->riskLevel,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'scope_mode' => $this->scopeMode,
            'task_contract_hash' => $this->taskContractHash,
            'task_kind' => $this->taskKind,
            'verification_profile' => $this->verificationProfile,
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
        $payload = $this->toCanonicalArray();
        foreach (self::HASH_EXCLUDES as $key) {
            unset($payload[$key]);
        }

        return CanonicalHasher::hash($payload);
    }

    public function isProviderSafe(): bool
    {
        return true;
    }

    public function withMiniSpecHash(string $hash): self
    {
        return new self(
            runId: $this->runId,
            envelopeHash: $this->envelopeHash,
            intentRaw: $this->intentRaw,
            intentNormalized: $this->intentNormalized,
            taskKind: $this->taskKind,
            riskLevel: $this->riskLevel,
            scopeMode: $this->scopeMode,
            mode: $this->mode,
            contextBudget: $this->contextBudget,
            docTiersRequired: $this->docTiersRequired,
            verificationProfile: $this->verificationProfile,
            contextDigest: $this->contextDigest,
            escalationTriggers: $this->escalationTriggers,
            miniSpecHash: $hash,
            taskContractHash: $this->taskContractHash,
            compactSddHash: $this->compactSddHash,
        );
    }

    public function withTaskContractHash(string $hash): self
    {
        return new self(
            runId: $this->runId,
            envelopeHash: $this->envelopeHash,
            intentRaw: $this->intentRaw,
            intentNormalized: $this->intentNormalized,
            taskKind: $this->taskKind,
            riskLevel: $this->riskLevel,
            scopeMode: $this->scopeMode,
            mode: $this->mode,
            contextBudget: $this->contextBudget,
            docTiersRequired: $this->docTiersRequired,
            verificationProfile: $this->verificationProfile,
            contextDigest: $this->contextDigest,
            escalationTriggers: $this->escalationTriggers,
            miniSpecHash: $this->miniSpecHash,
            taskContractHash: $hash,
            compactSddHash: $this->compactSddHash,
        );
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            runId: (string) $payload['run_id'],
            envelopeHash: (string) $payload['envelope_hash'],
            intentRaw: (string) $payload['intent_raw'],
            intentNormalized: (string) $payload['intent_normalized'],
            taskKind: (string) $payload['task_kind'],
            riskLevel: (string) $payload['risk_level'],
            scopeMode: (string) $payload['scope_mode'],
            mode: (string) $payload['mode'],
            contextBudget: ContextBudget::fromArray((array) $payload['context_budget']),
            docTiersRequired: array_values((array) ($payload['doc_tiers_required'] ?? [])),
            verificationProfile: (string) $payload['verification_profile'],
            contextDigest: $payload['context_digest'] ?? null,
            escalationTriggers: array_values((array) ($payload['escalation_triggers'] ?? [])),
            miniSpecHash: $payload['mini_spec_hash'] ?? null,
            taskContractHash: $payload['task_contract_hash'] ?? null,
            compactSddHash: (string) ($payload['compact_sdd_hash'] ?? ''),
        );
    }
}

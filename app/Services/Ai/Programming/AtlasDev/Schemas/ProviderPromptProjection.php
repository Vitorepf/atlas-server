<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\QualityChecks;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\ProviderSafeRedactor;
use InvalidArgumentException;

final class ProviderPromptProjection implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.provider_prompt_projection.v1';

    private const HASH_FIELD = 'prompt_projection_hash';

    /**
     * @param  array{
     *   task_contract_hash?: string,
     *   context_retrieval_plan_hash?: string,
     *   code_discovery_manifest_hash?: string,
     *   open_brain_projection_hash?: string,
     * }  $upstreamHashes  ledger of hashes this prompt projects from
     */
    public function __construct(
        public readonly string $runId,
        public readonly array $upstreamHashes,
        public readonly PromptSections $sections,
        public readonly QualityChecks $qualityChecks,
        public readonly string $renderedPromptText,
        public readonly string $renderedPromptHash,
        public readonly bool $providerSafe,
        public readonly string $promptProjectionHash,
    ) {
        if ($renderedPromptText === '') {
            throw new InvalidArgumentException(
                'ProviderPromptProjection requires rendered_prompt_text (contracts doc 5.4 invariant 7).'
            );
        }

        if ($renderedPromptHash === '') {
            throw new InvalidArgumentException(
                'ProviderPromptProjection requires rendered_prompt_hash matching rendered_prompt_text.'
            );
        }
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function isSendable(): bool
    {
        return $this->qualityChecks->allPassed()
            && $this->providerSafe
            && $this->renderedPromptText !== ''
            && $this->renderedPromptHash !== '';
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'prompt_projection_hash' => $this->promptProjectionHash,
            'provider_safe' => $this->providerSafe,
            'quality_checks' => $this->qualityChecks->toCanonicalArray(),
            'rendered_prompt_hash' => $this->renderedPromptHash,
            'rendered_prompt_text' => $this->renderedPromptText,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'sections' => $this->sections->toCanonicalArray(),
            'upstream_hashes' => $this->upstreamHashes,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        $canonical = $this->toCanonicalArray();
        if ($this->providerSafe) {
            return $canonical;
        }

        // Rendered prompt text is the only field that can carry arbitrary
        // content. When the projection is not provider-safe, redact it but
        // keep the hash so auditors can confirm the original text existed.
        $canonical['rendered_prompt_text'] = ProviderSafeRedactor::redactString(
            $this->renderedPromptText,
            'provider_prompt_projection.rendered_prompt_text',
        );

        return CanonicalJson::canonicalize($canonical);
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

    public static function fromArray(array $payload): self
    {
        return new self(
            runId: (string) $payload['run_id'],
            upstreamHashes: (array) ($payload['upstream_hashes'] ?? []),
            sections: PromptSections::fromArray((array) $payload['sections']),
            qualityChecks: QualityChecks::fromArray((array) $payload['quality_checks']),
            renderedPromptText: (string) ($payload['rendered_prompt_text'] ?? ''),
            renderedPromptHash: (string) ($payload['rendered_prompt_hash'] ?? ''),
            providerSafe: (bool) ($payload['provider_safe'] ?? true),
            promptProjectionHash: (string) ($payload['prompt_projection_hash'] ?? ''),
        );
    }
}

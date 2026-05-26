<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class AtlasDevOperationEnvelope implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.operation_envelope.v1';

    /**
     * Canonical flow identifier for the Atlas Dev runtime. Atlas Dev is a
     * specialised workspace-development flow inside Atlas AI; the envelope
     * always carries this exact value. Any other flow id is non-canonical
     * and surfaces a defect upstream (intake handed us the wrong flow).
     */
    public const FLOW_ID = 'atlas_dev';

    /**
     * Routed through the Atlas AI Router (global product entrypoint).
     */
    public const FLOW_ORIGIN_ATLAS_AI_ROUTER = 'atlas_ai_router';

    /**
     * Direct entry — CLI dev/debug invocation that skipped the Atlas AI
     * Router. The runtime treats both origins identically; this field exists
     * only as auditable metadata.
     */
    public const FLOW_ORIGIN_DIRECT = 'direct';

    public const FLOW_ORIGINS = [
        self::FLOW_ORIGIN_ATLAS_AI_ROUTER,
        self::FLOW_ORIGIN_DIRECT,
    ];

    private const HASH_FIELD = 'envelope_hash';

    /**
     * @param  list<string>  $userConstraints
     * @param  string  $flowId  canonical flow identifier; always {@see self::FLOW_ID}
     * @param  string  $flowOrigin  one of {@see self::FLOW_ORIGINS}
     * @param  string|null  $commandIntent  optional hint about which command/intent
     *                                      the Atlas AI Router resolved to (e.g. "dev",
     *                                      "debug"). It is metadata only; it MUST NOT
     *                                      alter raw_intent / normalized_intent.
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $surfaceId,
        public readonly SurfaceContext $surfaceContext,
        public readonly string $workspace,
        public readonly string $workspaceHash,
        public readonly GitState $gitState,
        public readonly string $rawIntent,
        public readonly string $normalizedIntent,
        public readonly array $userConstraints,
        public readonly string $intentClarityLevel,
        public readonly string $dirtyWorktreePolicy,
        public readonly Preflight $preflight,
        public readonly string $envelopeHash,
        public readonly string $flowId = self::FLOW_ID,
        public readonly string $flowOrigin = self::FLOW_ORIGIN_DIRECT,
        public readonly ?string $commandIntent = null,
    ) {}

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'command_intent' => $this->commandIntent,
            'dirty_worktree_policy' => $this->dirtyWorktreePolicy,
            'envelope_hash' => $this->envelopeHash,
            'flow_id' => $this->flowId,
            'flow_origin' => $this->flowOrigin,
            'git_state' => $this->gitState->toCanonicalArray(),
            'intent_clarity_level' => $this->intentClarityLevel,
            'normalized_intent' => $this->normalizedIntent,
            'preflight' => $this->preflight->toCanonicalArray(),
            'provider_safe' => true,
            'raw_intent' => $this->rawIntent,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'surface_context' => $this->surfaceContext->toCanonicalArray(),
            'surface_id' => $this->surfaceId,
            'user_constraints' => array_values($this->userConstraints),
            'workspace' => $this->workspace,
            'workspace_hash' => $this->workspaceHash,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        // OperationEnvelope is provider_safe=true by invariant; the canonical
        // payload is the projection. Components are already provider-safe.
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

    /**
     * Hint helper: is the envelope's flow_id the canonical value? Validators
     * and tests use this instead of comparing literals.
     */
    public function isCanonicalFlowId(): bool
    {
        return $this->flowId === self::FLOW_ID;
    }

    /**
     * Hint helper: is the envelope's flow_origin one of the two canonical
     * values? Anything else means intake produced something unrecognised.
     */
    public function isCanonicalFlowOrigin(): bool
    {
        return in_array($this->flowOrigin, self::FLOW_ORIGINS, true);
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            runId: (string) $payload['run_id'],
            surfaceId: (string) $payload['surface_id'],
            surfaceContext: SurfaceContext::fromArray((array) $payload['surface_context']),
            workspace: (string) $payload['workspace'],
            workspaceHash: (string) $payload['workspace_hash'],
            gitState: GitState::fromArray((array) $payload['git_state']),
            rawIntent: (string) $payload['raw_intent'],
            normalizedIntent: (string) $payload['normalized_intent'],
            userConstraints: array_values((array) ($payload['user_constraints'] ?? [])),
            intentClarityLevel: (string) $payload['intent_clarity_level'],
            dirtyWorktreePolicy: (string) $payload['dirty_worktree_policy'],
            preflight: Preflight::fromArray((array) $payload['preflight']),
            envelopeHash: (string) ($payload['envelope_hash'] ?? ''),
            flowId: array_key_exists('flow_id', $payload) && is_string($payload['flow_id']) && $payload['flow_id'] !== ''
                ? $payload['flow_id']
                : self::FLOW_ID,
            flowOrigin: array_key_exists('flow_origin', $payload) && is_string($payload['flow_origin']) && $payload['flow_origin'] !== ''
                ? $payload['flow_origin']
                : self::FLOW_ORIGIN_DIRECT,
            commandIntent: array_key_exists('command_intent', $payload) && is_string($payload['command_intent']) && $payload['command_intent'] !== ''
                ? $payload['command_intent']
                : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Surface;

use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use InvalidArgumentException;

/**
 * Atlas CLI Dev surface adapter (`atlas:cli:dev --efficient`).
 *
 * Translates a terminal-shaped payload into an {@see OperationEnvelope} for
 * the core, then projects {@see PlanOnlyResult} into a wire shape suitable
 * for the CLI command. The adapter never renders text itself — it returns a
 * canonical array shaped exactly like the Desktop response **minus**
 * `ui_hints` — so the command can decide between text or JSON rendering
 * without surface-specific knowledge leaking back into core.
 *
 * Surface_id is locked to `atlas_cli_dev`. The adapter accepts the legacy
 * alias `atlas_cli_dev_efficient` for compatibility with future Router
 * routing, but always normalises to `atlas_cli_dev` downstream.
 */
final class AtlasCliDevAdapter implements AtlasDevSurfaceAdapter
{
    use SurfacePayloadFields;

    public const SURFACE_ID = 'atlas_cli_dev';

    private const ACCEPTED_SURFACE_IDS = [
        self::SURFACE_ID,
        'atlas_cli_dev_efficient',
    ];

    public function __construct(
        private readonly IntakeNormalizer $intake,
        private readonly SurfaceResponseFormatter $formatter,
    ) {}

    public function surfaceId(): string
    {
        return self::SURFACE_ID;
    }

    /**
     * @param  array<string, mixed>  $payload  shaped by the CLI command:
     *                                         - workspace: string (absolute, defaulted by command to cwd)
     *                                         - raw_intent: string (joined task text)
     *                                         - user_constraints: list<string>
     *                                         - surface_id?: string (override; restricted to ACCEPTED_SURFACE_IDS)
     *                                         - thread_id?, conversation_id?, composer_mode?, composer_task?
     *                                         - provider_choice?, previous_run_id?
     *                                         - flow_origin?, command_intent? (Router-resolved when present)
     */
    public function buildEnvelope(array $payload): OperationEnvelope
    {
        $surfaceId = $this->resolveSurfaceId($payload);
        $workspace = $this->stringField($payload, 'workspace');
        $rawIntent = $this->stringField($payload, 'raw_intent');
        $userConstraints = $this->stringListField($payload, 'user_constraints');
        $surfaceHints = $this->extractSurfaceHints($payload);

        if ($rawIntent === '') {
            throw new InvalidArgumentException('atlas_cli_dev payload requires non-empty raw_intent.');
        }

        if ($workspace === '') {
            throw new InvalidArgumentException('atlas_cli_dev payload requires workspace.');
        }

        return $this->intake->normalize(
            surfaceId: $surfaceId,
            workspace: $workspace,
            rawIntent: $rawIntent,
            userConstraints: $userConstraints,
            surfaceHints: $surfaceHints,
        );
    }

    public function formatPlanOnly(PlanOnlyResult $result): array
    {
        // Canonical projection only — no ui_hints. CLI is text-or-JSON and
        // both renderers consume the same shape the Desktop response uses
        // (minus the Desktop-specific hint block).
        return $this->formatter->formatPlanOnly($result);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSurfaceId(array $payload): string
    {
        $candidate = $payload['surface_id'] ?? self::SURFACE_ID;
        if (! is_string($candidate) || $candidate === '') {
            return self::SURFACE_ID;
        }
        if (! in_array($candidate, self::ACCEPTED_SURFACE_IDS, true)) {
            throw new InvalidArgumentException(
                "Unsupported surface_id '{$candidate}' for Atlas CLI Dev adapter."
            );
        }

        return self::SURFACE_ID;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractSurfaceHints(array $payload): array
    {
        $hints = [];
        foreach (
            ['thread_id', 'conversation_id', 'composer_mode', 'composer_task', 'provider_choice', 'previous_run_id', 'flow_origin', 'command_intent'] as $key
        ) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $hints[$key] = trim($value);
            }
        }
        if (isset($payload['operator_explicit']) && is_bool($payload['operator_explicit'])) {
            $hints['operator_explicit'] = $payload['operator_explicit'];
        }

        return $hints;
    }
}

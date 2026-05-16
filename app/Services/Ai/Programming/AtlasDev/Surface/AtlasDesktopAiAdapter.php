<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Surface;

use App\Services\Ai\Programming\AtlasDev\Pipeline\IntakeNormalizer;
use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use InvalidArgumentException;

/**
 * Atlas AI Router → Atlas Dev flow plumbing.
 *
 * Atlas AI is the global product entrypoint; the Router decides whether a
 * given operator message belongs in the Atlas Dev flow and, if so, posts
 * to /ai/interactions/atlas-dev/plan with `flow_origin=atlas_ai_router`. A
 * direct CLI/dev invocation reaches the same endpoint but stamps
 * `flow_origin=direct`. Either way Atlas Dev runtime stays surface-agnostic;
 * the only adapter aware of these wire keys is this class.
 */

/**
 * Atlas Desktop AI surface adapter.
 *
 * Consumes the JSON body of `POST /ai/interactions/atlas-dev/plan` and emits:
 *   - an {@see OperationEnvelope} (via {@see IntakeNormalizer}) for the core;
 *   - a Desktop response (canonical projection + `ui_hints`) for the cockpit.
 *
 * Surface-specific knowledge starts and ends in this file: payload keys,
 * default surface_id, allowed surface_id override list, Desktop ui_hints.
 * Nothing outside `AtlasDev/Surface/` may know these strings.
 */
final class AtlasDesktopAiAdapter implements AtlasDevSurfaceAdapter
{
    public const SURFACE_ID = 'atlas_desktop_ai';

    /**
     * Bodies that arrive over /ai/interactions are normally tagged with the
     * native surface id. A few legacy clients send the broader Atlas Desktop
     * id; both are accepted here. Anything else is rejected to keep the
     * adapter boundary tight.
     */
    private const ACCEPTED_SURFACE_IDS = [
        self::SURFACE_ID,
        'atlas_ai_desktop_mac',
    ];

    public function __construct(
        private readonly IntakeNormalizer $intake,
        private readonly SurfaceResponseFormatter $formatter,
        private readonly DesktopUiHintsBuilder $hintsBuilder,
    ) {}

    public function surfaceId(): string
    {
        return self::SURFACE_ID;
    }

    public function buildEnvelope(array $payload): OperationEnvelope
    {
        $surfaceId = $this->resolveSurfaceId($payload);
        $workspace = $this->stringField($payload, 'workspace');
        $rawIntent = $this->stringField($payload, 'raw_intent');
        $userConstraints = $this->stringListField($payload, 'user_constraints');
        $surfaceHints = $this->extractSurfaceHints($payload);

        if ($rawIntent === '') {
            throw new InvalidArgumentException('atlas_desktop_ai payload requires non-empty raw_intent.');
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
        $response = $this->formatter->formatPlanOnly($result);
        $response['ui_hints'] = $this->hintsBuilder->build($result);

        return $response;
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
                "Unsupported surface_id '{$candidate}' for Atlas Desktop AI adapter."
            );
        }

        return self::SURFACE_ID;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';
        if (! is_string($value)) {
            return '';
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function stringListField(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $out[] = trim($entry);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractSurfaceHints(array $payload): array
    {
        $hints = [];
        foreach (['thread_id', 'conversation_id', 'composer_mode', 'composer_task', 'provider_choice', 'previous_run_id'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $hints[$key] = trim($value);
            }
        }
        if (isset($payload['operator_explicit']) && is_bool($payload['operator_explicit'])) {
            $hints['operator_explicit'] = $payload['operator_explicit'];
        }
        if (isset($payload['attachments']) && is_array($payload['attachments'])) {
            // Attachments are not fed into the envelope (no IO here); surface
            // hint carries the count so downstream layers know it existed.
            $hints['attachments_count'] = count($payload['attachments']);
        }
        if (isset($payload['policy_hints']) && is_array($payload['policy_hints'])) {
            $hints['policy_hints'] = $payload['policy_hints'];
        }

        // Atlas AI > Router > Atlas Dev: forward flow_origin and command_intent
        // (the Router-resolved command kind). The IntakeNormalizer validates
        // both against the canonical enum and ignores anything unknown.
        if (isset($payload['flow_origin']) && is_string($payload['flow_origin']) && trim($payload['flow_origin']) !== '') {
            $hints['flow_origin'] = trim($payload['flow_origin']);
        }
        if (isset($payload['command_intent']) && is_string($payload['command_intent']) && trim($payload['command_intent']) !== '') {
            $hints['command_intent'] = trim($payload['command_intent']);
        }

        return $hints;
    }
}

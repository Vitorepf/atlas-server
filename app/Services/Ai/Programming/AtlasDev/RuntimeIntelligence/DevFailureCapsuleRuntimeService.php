<?php

namespace App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence;

use App\Models\AtlasDevFailureCapsule;
use App\Models\AtlasDevTaskPacket;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;

class DevFailureCapsuleRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.dev.failure_capsule.v1';

    private const SECRET_PATTERNS = [
        '/\b[A-Z0-9_]*API[_-]?KEY[A-Z0-9_]*\b/i' => 'REDACTED_PROVIDER_TOKEN_NAME',
        '/\bAWS_SECRET_ACCESS_KEY\b/i' => 'REDACTED_PROVIDER_TOKEN_NAME',
        '/authorization:\s*bearer\s+[A-Za-z0-9._\-]+/i' => 'authorization: bearer REDACTED',
        '/bearer\s+ey[A-Za-z0-9._\-]+/i' => 'bearer REDACTED',
        '/sk-ant-[A-Za-z0-9._\-]+/i' => 'sk-ant-REDACTED',
        '/sk-[A-Za-z0-9]{20,}/i' => 'sk-REDACTED',
        '/password\s*=\s*[^\s,;]+/i' => 'password=REDACTED',
        '/secret\s*=\s*[^\s,;]+/i' => 'secret=REDACTED',
        '/private_key/i' => 'REDACTED_PRIVATE_KEY_LABEL',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function build(array $input, ?array $packet = null): array
    {
        $runId = (string) ($input['run_id'] ?? $packet['run_id'] ?? 'unknown');
        $taskId = (string) ($input['task_id'] ?? $packet['task_id'] ?? 'unknown');
        $failureClass = $this->classify((string) ($input['failure_class'] ?? ''), (string) ($input['error'] ?? $input['error_excerpt'] ?? ''));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'task_id' => $taskId,
            'failing_gate' => AiValueNormalizer::trimmedScalarStringOrNull($input['failing_gate'] ?? null) ?? 'unknown',
            'failure_class' => $failureClass,
            'error_excerpt' => $this->truncate(self::redactErrorExcerpt((string) ($input['error_excerpt'] ?? $input['error'] ?? '')), 1200),
            'changed_files' => AtlasDevStringListNormalizer::uniqueTrimmedScalarValues($input['changed_files'] ?? []),
            'suggested_repair' => AiValueNormalizer::trimmedScalarStringOrNull($input['suggested_repair'] ?? null) ?? $this->defaultRepair($failureClass),
            'retry_budget' => max(0, min(3, (int) ($input['retry_budget'] ?? 1))),
            'escalate_to_forge' => (bool) ($input['escalate_to_forge'] ?? in_array($failureClass, ['scope_violation', 'architecture_risk'], true)),
        ];
        $payload['failure_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function persist(array $input, ?AtlasDevTaskPacket $taskPacket = null): AtlasDevFailureCapsule
    {
        $payload = $this->build($input, $taskPacket?->toArray());

        return AtlasDevFailureCapsule::query()->updateOrCreate(
            ['failure_hash' => $payload['failure_hash']],
            [
                'schema_version' => $payload['schema_version'],
                'uuid' => $payload['failure_hash'],
                'run_id' => $payload['run_id'],
                'task_id' => $payload['task_id'],
                'task_packet_id' => $taskPacket?->id,
                'failing_gate' => $payload['failing_gate'],
                'failure_class' => $payload['failure_class'],
                'error_excerpt' => $payload['error_excerpt'],
                'changed_files' => $payload['changed_files'],
                'suggested_repair' => $payload['suggested_repair'],
                'retry_budget' => $payload['retry_budget'],
                'escalate_to_forge' => $payload['escalate_to_forge'],
            ],
        );
    }

    public static function redactErrorExcerpt(string $value): string
    {
        foreach (self::SECRET_PATTERNS as $pattern => $replacement) {
            $value = (string) preg_replace($pattern, $replacement, $value);
        }

        return $value;
    }

    private function classify(string $declared, string $error): string
    {
        if (in_array($declared, ['test_failure', 'type_error', 'scope_violation', 'missing_context', 'architecture_risk', 'weak_output', 'no_patch_produced', 'prompt_not_sendable', 'timeout', 'infra_error', 'unknown'], true)) {
            return $declared;
        }

        $error = strtolower($error);

        // Structured senior-loop excerpts ("completion=X, scope=Y, verification=Z")
        // classify by the actual gate values FIRST — the old bag-of-words matcher
        // saw the literal "scope=passed" boilerplate and stamped half the real
        // corpus as scope_violation (28/56 audited 02/07), poisoning every
        // known_failure_modes injection with a lie.
        if (preg_match('/completion=([a-z_]+)/', $error, $m) === 1) {
            return match (true) {
                $m[1] === 'no_patch_needed' => 'no_patch_produced',
                $m[1] === 'needs_review' => 'weak_output',
                str_contains($error, 'scope=failed') => 'scope_violation',
                str_contains($error, 'verification=failed') => 'test_failure',
                default => 'unknown',
            };
        }

        // Harness-side failures (the provider never even ran): the lesson is for
        // the HARNESS, not the model — a distinct class keeps the injected
        // known_failure_modes honest about who failed.
        if (str_contains($error, 'prompt_projection_not_sendable')) {
            return 'prompt_not_sendable';
        }
        if (str_contains($error, 'timeout') || str_contains($error, 'timed out')) {
            return 'timeout';
        }
        if (str_contains($error, 'worker_unbound') || str_contains($error, 'cli_error') || str_contains($error, 'driver')) {
            return 'infra_error';
        }

        return match (true) {
            str_contains($error, 'weak_output') || str_contains($error, 'placeholder') => 'weak_output',
            str_contains($error, 'scope violation') || str_contains($error, 'scope_violation') || str_contains($error, 'scope=failed') || str_contains($error, 'forbidden') => 'scope_violation',
            str_contains($error, 'context') || str_contains($error, 'rag') => 'missing_context',
            str_contains($error, 'type') || str_contains($error, 'phpstan') || str_contains($error, 'tsc') => 'type_error',
            str_contains($error, 'architecture') || str_contains($error, 'boundary') => 'architecture_risk',
            str_contains($error, 'failed') || str_contains($error, 'failure') || str_contains($error, 'assert') => 'test_failure',
            default => 'unknown',
        };
    }

    private function defaultRepair(string $failureClass): string
    {
        return match ($failureClass) {
            'scope_violation' => 'reduce patch to allowed files or escalate to Forge with explicit Obra scope',
            'missing_context' => 'rebuild context pack with owner docs and affected files before retry',
            'type_error' => 'fix type/static-analysis failure and rerun the narrow impacted gate',
            'architecture_risk' => 'pause Dev fast path and promote to Forge/Senior review',
            'test_failure' => 'use failure capsule to repair the minimal failing behavior and rerun selected tests',
            'weak_output' => 'replace placeholder/TODO/fake-assert output with a concrete implementation; do not ship vacuously green diffs',
            'no_patch_produced' => 'the model returned no diff for a write task in this area before: produce a concrete patch — restate the target files and emit a unified diff, never an explanation-only reply',
            'prompt_not_sendable' => 'harness-side: the prompt projection failed a sendability guard before the provider ran — fix prompt assembly (leakage/forbidden sections), not the model output',
            'timeout' => 'previous run in this area timed out: prefer a smaller focused change and the narrowest verification command',
            'infra_error' => 'harness-side: provider driver/worker was unavailable — check bindings and provider health before blaming the diff',
            default => 'inspect failure capsule, add missing evidence and retry once',
        };
    }

    private function truncate(string $value, int $limit): string
    {
        $value = trim($value);

        return strlen($value) <= $limit ? $value : substr($value, 0, $limit - 3).'...';
    }
}

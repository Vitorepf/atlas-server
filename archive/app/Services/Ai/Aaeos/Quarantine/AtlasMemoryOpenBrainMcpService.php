<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Memory · Open Brain MCP & API contract decider.
 *
 * Pure, deterministic enforcement of the access boundary documented in the
 * "Open Brain MCP And API Contract" spec. Open Brain is an access layer over
 * Atlas context — it builds/exports context packs and returns refs, but it is
 * NOT a second brain with independent authority. This service answers four
 * controlled questions a surface needs before trusting Open Brain:
 *
 *   1. boundary / mayPerform()  — what Open Brain can and cannot do.
 *   2. validateExport()         — does an export honor the Required Export Shape
 *                                 and provider-safe safety summary?
 *   3. validateInjectionResult()— is a context-injection result well-formed and
 *                                 still NOT a Decision Receipt?
 *   4. route()                  — does this intent stay in Open Brain, or must it
 *                                 go through the Atlas Kernel pipeline?
 *
 * Contract (from the doc):
 *   Boundary — Open Brain CAN build/export context packs, return docs/code/memory
 *   refs, expose MCP/API/CLI context, audit requester/export/hash, serve
 *   local/remote controlled consumers. It CANNOT choose providers, execute
 *   runtime tools, promote memory silently, bypass privacy or policy, or become
 *   source of truth.
 *
 *   Required Export Shape — every export must include schema version,
 *   requester/surface, workspace/task hints, policy result, context refs by
 *   type, provider-safe summaries, truncation/budget metadata, audit hash/trace
 *   id and a generated timestamp. Context pack exports must expose `safety` as
 *   `atlas.open_brain.context_pack_safety.v1` declaring `provider_safe_only=true`,
 *   `raw_content_exposed=false`, `raw_content_persisted=false`,
 *   `audit_query_raw_content_persisted=false`. Memory recall surfaces must keep
 *   `raw_content_persisted_count=0` for provider-safe recall.
 *
 *   Context Injection Contract — a surface sends an injection request (with the
 *   listed hints) and receives a result whose status is one of `injected`,
 *   `skipped`, `degraded`, `failed_open` or `failed_closed`. "The injection
 *   result is a context export. It is not a Decision Receipt and does not
 *   authorize provider calls by itself."
 *
 *   Operational Rule — "If an external tool wants context, it asks Open Brain. If
 *   it wants to decide, execute, repair, learn or persist, it must go through the
 *   Atlas Kernel pipeline and Decision Receipt rules."
 *
 *   Future Scope Requiring AP — streamable HTTP/SSE, multiuser sync, external
 *   vector DB, provider-owned memory merge, autonomous memory promotion.
 *
 * The service NEVER builds a real pack, calls a provider, queries a database or
 * mutates state. It only judges shapes and routes intents; callers enforce.
 *
 * @see docs/engineering-knowledge-base/memory/open-brain-mcp.md
 */
final class AtlasMemoryOpenBrainMcpService
{
    /** Stable schema id for the safety summary every context pack export carries. */
    public const CONTEXT_PACK_SAFETY_SCHEMA = 'atlas.open_brain.context_pack_safety.v1';

    /** Stable schema id for the sanitized audit-query safety summary. */
    public const AUDIT_QUERY_SAFETY_SCHEMA = 'atlas.open_brain.audit_query_safety.v1';

    /**
     * Capabilities Open Brain MAY exercise (doc Boundary "It can").
     *
     * @var list<string>
     */
    public const CAN = [
        'build_export_context_packs',
        'return_docs_code_memory_refs',
        'expose_mcp_api_cli_context',
        'audit_requester_export_hash',
        'serve_local_remote_controlled_consumers',
    ];

    /**
     * Capabilities Open Brain MUST NOT exercise (doc Boundary "It cannot").
     *
     * @var list<string>
     */
    public const CANNOT = [
        'choose_providers',
        'execute_runtime_tools',
        'promote_memory_silently',
        'bypass_privacy_or_policy',
        'become_source_of_truth',
    ];

    /**
     * The mandatory keys of the Required Export Shape (doc "Required Export
     * Shape" — every export must include these nine).
     *
     * @var list<string>
     */
    public const REQUIRED_EXPORT_FIELDS = [
        'schema_version',
        'requester_surface',
        'workspace_task_hints',
        'policy_result',
        'context_refs',
        'provider_safe_summaries',
        'truncation_budget_metadata',
        'audit_ref',
        'generated_at',
    ];

    /**
     * Required result fields of a context-injection result (doc "Required result
     * fields").
     *
     * @var list<string>
     */
    public const REQUIRED_INJECTION_FIELDS = [
        'status',
        'context_pack_hash',
        'prompt_section_hash',
        'audit_ref',
        'ref_counts',
        'provider_safe',
        'truncation',
        'warnings',
    ];

    /** Closed set of injection result statuses (doc "status:"). */
    public const INJECTION_STATUSES = [
        'injected',
        'skipped',
        'degraded',
        'failed_open',
        'failed_closed',
    ];

    /** Allowed request surfaces (doc "Required request hints — surface"). */
    public const SURFACES = [
        'cli_dev',
        'cli_continue',
        'cli_chat',
        'app_ai',
        'mobile',
        'voice_realtime',
        'mcp_consumer',
    ];

    /** Kernel-approved request modes (doc "Required request hints — mode"). */
    public const MODES = [
        'dev',
        'debug',
        'review',
        'programming',
        'direct',
        'plan',
    ];

    /** Policy modes a request may carry (doc "policy mode"). */
    public const POLICY_MODES = ['off', 'auto', 'required'];

    /** Routing targets for the Operational Rule. */
    public const TARGET_OPEN_BRAIN = 'open_brain';
    public const TARGET_KERNEL = 'atlas_kernel';

    /**
     * Intents that must leave Open Brain and run through the Atlas Kernel
     * pipeline + Decision Receipt rules (doc Operational Rule). Anything that is
     * not a pure context request lands here.
     *
     * @var list<string>
     */
    public const KERNEL_INTENTS = ['decide', 'execute', 'repair', 'learn', 'persist'];

    /**
     * Capabilities whose activation requires a dedicated AP — they are NOT in
     * scope for Open Brain today (doc "Future Scope Requiring AP").
     *
     * @var list<string>
     */
    public const FUTURE_SCOPE_REQUIRES_AP = [
        'streamable_http_sse',
        'multiuser_open_brain_sync',
        'external_vector_db',
        'provider_owned_memory_merge',
        'autonomous_memory_promotion',
    ];

    /**
     * Decide whether Open Brain is permitted to perform a capability.
     * Anything not on the explicit CAN allowlist — including every CANNOT item —
     * is denied by default (closed boundary).
     */
    public function mayPerform(string $capability): bool
    {
        return in_array($this->norm($capability), self::CAN, true);
    }

    /**
     * The full access boundary as a structured manifest.
     *
     * @return array<string,mixed>
     */
    public function boundary(): array
    {
        return [
            'authority' => 'access_layer_not_independent_authority',
            'can' => self::CAN,
            'cannot' => self::CANNOT,
        ];
    }

    /**
     * Validate a context-pack export against the Required Export Shape and the
     * provider-safe safety summary.
     *
     * Rules enforced:
     *   - all nine REQUIRED_EXPORT_FIELDS must be present;
     *   - `safety.schema` must equal the context_pack_safety.v1 schema;
     *   - safety must declare provider_safe_only=true, raw_content_exposed=false,
     *     raw_content_persisted=false, audit_query_raw_content_persisted=false;
     *   - recall safety counts (when present) must keep
     *     raw_content_persisted_count=0 (verbatim/redacted-backed recall stays
     *     provider-safe).
     *
     * @param array<string,mixed> $export
     * @return array<string,mixed> { valid, missing_fields, violations, provider_safe }
     */
    public function validateExport(array $export): array
    {
        $missing = [];
        foreach (self::REQUIRED_EXPORT_FIELDS as $field) {
            if (! array_key_exists($field, $export)) {
                $missing[] = $field;
            }
        }

        $violations = [];
        $safety = is_array($export['safety'] ?? null) ? $export['safety'] : [];

        if (($safety['schema'] ?? null) !== self::CONTEXT_PACK_SAFETY_SCHEMA) {
            $violations[] = 'safety_schema_must_be_context_pack_safety_v1';
        }
        if (($safety['provider_safe_only'] ?? null) !== true) {
            $violations[] = 'provider_safe_only_must_be_true';
        }
        if (($safety['raw_content_exposed'] ?? null) !== false) {
            $violations[] = 'raw_content_exposed_must_be_false';
        }
        if (($safety['raw_content_persisted'] ?? null) !== false) {
            $violations[] = 'raw_content_persisted_must_be_false';
        }
        if (($safety['audit_query_raw_content_persisted'] ?? null) !== false) {
            $violations[] = 'audit_query_raw_content_persisted_must_be_false';
        }

        // Recall safety counts are optional, but if declared they must keep the
        // provider-safe invariant: zero raw content persisted.
        if (array_key_exists('raw_content_persisted_count', $safety)
            && (int) $safety['raw_content_persisted_count'] !== 0) {
            $violations[] = 'raw_content_persisted_count_must_be_zero';
        }

        $valid = $missing === [] && $violations === [];

        return [
            'schema' => self::CONTEXT_PACK_SAFETY_SCHEMA,
            'valid' => $valid,
            'missing_fields' => $missing,
            'violations' => $violations,
            'provider_safe' => $valid,
        ];
    }

    /**
     * Validate a context-injection result.
     *
     * Rules enforced:
     *   - all required injection result fields must be present;
     *   - `status` must be one of the five closed statuses;
     *   - the result is a context export, never a Decision Receipt, so it can
     *     never authorize provider calls by itself.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function validateInjectionResult(array $result): array
    {
        $missing = [];
        foreach (self::REQUIRED_INJECTION_FIELDS as $field) {
            if (! array_key_exists($field, $result)) {
                $missing[] = $field;
            }
        }

        $status = is_string($result['status'] ?? null) ? $result['status'] : '';
        $statusValid = in_array($status, self::INJECTION_STATUSES, true);

        $violations = [];
        if (! $statusValid) {
            $violations[] = 'status_must_be_a_known_injection_status';
        }

        return [
            'valid' => $missing === [] && $statusValid,
            'status' => $statusValid ? $status : null,
            'missing_fields' => $missing,
            'violations' => $violations,
            // "It is not a Decision Receipt and does not authorize provider
            // calls by itself."
            'is_decision_receipt' => false,
            'authorizes_provider_calls' => false,
        ];
    }

    /**
     * Validate the request hints a surface sends with an injection/export
     * request (doc "Required request hints").
     *
     * @param array<string,mixed> $hints
     * @return array<string,mixed>
     */
    public function validateRequestHints(array $hints): array
    {
        $violations = [];

        $surface = is_string($hints['surface'] ?? null) ? $hints['surface'] : '';
        if (! in_array($surface, self::SURFACES, true)) {
            $violations[] = 'surface_must_be_a_supported_surface';
        }

        $mode = is_string($hints['mode'] ?? null) ? $hints['mode'] : '';
        if (! in_array($mode, self::MODES, true)) {
            $violations[] = 'mode_must_be_kernel_approved';
        }

        $policyMode = is_string($hints['policy_mode'] ?? null) ? $hints['policy_mode'] : '';
        if (! in_array($policyMode, self::POLICY_MODES, true)) {
            $violations[] = 'policy_mode_must_be_off_auto_or_required';
        }

        $workspace = trim((string) ($hints['workspace'] ?? ''));
        if ($workspace === '') {
            $violations[] = 'workspace_is_required';
        }

        $objective = trim((string) ($hints['objective'] ?? ''));
        if ($objective === '') {
            $violations[] = 'objective_is_required';
        }

        return [
            'valid' => $violations === [],
            'violations' => $violations,
        ];
    }

    /**
     * Apply the Operational Rule: route an intent to Open Brain or the Kernel.
     *
     * "If an external tool wants context, it asks Open Brain. If it wants to
     * decide, execute, repair, learn or persist, it must go through the Atlas
     * Kernel pipeline and Decision Receipt rules."
     *
     * @return array<string,mixed>
     */
    public function route(string $intent): array
    {
        $normalized = $this->norm($intent);
        $isKernel = in_array($normalized, self::KERNEL_INTENTS, true);

        return [
            'intent' => $normalized,
            'target' => $isKernel ? self::TARGET_KERNEL : self::TARGET_OPEN_BRAIN,
            'requires_decision_receipt' => $isKernel,
            'reason' => $isKernel
                ? 'mutating_intent_must_go_through_atlas_kernel_pipeline'
                : 'pure_context_request_served_by_open_brain',
        ];
    }

    /**
     * Whether a capability is future scope that needs a dedicated AP before it
     * may be activated (doc "Future Scope Requiring AP").
     */
    public function requiresAp(string $capability): bool
    {
        return in_array($this->norm($capability), self::FUTURE_SCOPE_REQUIRES_AP, true);
    }

    /**
     * Canonical contract manifest: the boundary, the required shapes, the closed
     * enums and the governing invariants.
     *
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return [
            'schema_version' => self::CONTEXT_PACK_SAFETY_SCHEMA,
            'authority' => 'access_layer_not_independent_authority',
            'boundary' => $this->boundary(),
            'required_export_fields' => self::REQUIRED_EXPORT_FIELDS,
            'required_injection_fields' => self::REQUIRED_INJECTION_FIELDS,
            'injection_statuses' => self::INJECTION_STATUSES,
            'surfaces' => self::SURFACES,
            'modes' => self::MODES,
            'policy_modes' => self::POLICY_MODES,
            'kernel_intents' => self::KERNEL_INTENTS,
            'future_scope_requires_ap' => self::FUTURE_SCOPE_REQUIRES_AP,
            'invariants' => [
                'open_brain_exports_context_it_does_not_decide_execute_or_promote',
                'every_export_carries_required_shape_and_provider_safe_safety_summary',
                'raw_content_never_exposed_or_persisted',
                'audit_query_stores_hashes_lengths_labels_only',
                'injection_result_is_not_a_decision_receipt',
                'mutating_intents_route_to_atlas_kernel_pipeline',
                'future_scope_capabilities_require_dedicated_ap',
            ],
        ];
    }

    private function norm(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'unknown';
        }

        return strtolower(trim($value));
    }
}

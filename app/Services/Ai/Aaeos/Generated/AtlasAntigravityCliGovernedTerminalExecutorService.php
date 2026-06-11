<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Atlas Antigravity CLI (`agy`) governed-terminal policy classifier.
 *
 * Pure, deterministic implementation of the governance contract documented in
 * the v1 doc. This is intentionally NOT a CLI wrapper/driver — the doc's
 * canonical state is `reference_only_sdk_first_no_cli_driver`, and building a
 * runtime driver for `agy` is a forbidden_change while the SDK-first route is
 * active. What IS faithful runtime here is the policy decider that classifies
 * an `agy` flag / subcommand / slash-command as allowed, forbidden or
 * review-required, decides whether a proposed CLI usage is a sovereign-Atlas
 * violation, and emits the minimal `atlas.antigravity_cli_reference.v1`
 * envelope with `implementation_allowed=false`.
 *
 * Contract enforced (from the doc):
 *   - "`--dangerously-skip-permissions` e proibido" — hard, unconditional stop
 *     for any Atlas workspace, CI, governed measurement or canonical experiment.
 *   - "`--sandbox` deve ser o default operacional" — its absence on an Atlas
 *     workspace experiment is a risk event, not a baseline.
 *   - "Nunca use `--add-dir` para burlar allowed files" — scope cannot widen
 *     without updating the Work Packet.
 *   - "`/model` e selector sao observacao, nao roteamento soberano" — a model
 *     seen in the CLI is recorded as `model_observed`, never as Atlas policy.
 *   - "Plugins/MCP ... exigem review antes de tocar workspace Atlas."
 *   - "Proibido criar wrapper/driver CLI enquanto a rota SDK-first estiver
 *     ativa" — `implementation_allowed` is always false.
 *   - The reference flow is observe -> record risk -> implement via SDK; the
 *     driver flow `agy --print --sandbox -> receipts -> gates` is forbidden.
 *
 * The service NEVER spawns `agy`, never executes a command, never queries a
 * database and never mutates state. It emits verdicts + an audit envelope;
 * callers enforce.
 *
 * @see docs/engineering-knowledge-base/atlas-antigravity-cli-governed-terminal-executor-v1.md
 */
final class AtlasAntigravityCliGovernedTerminalExecutorService
{
    /** Stable schema id for the minimal-data envelope this service emits. */
    public const REFERENCE_SCHEMA = 'atlas.antigravity_cli_reference.v1';

    /** Closed verdict set for one CLI token classification. */
    public const VERDICT_FORBIDDEN = 'forbidden';
    public const VERDICT_REVIEW_REQUIRED = 'review_required';
    public const VERDICT_REFERENCE_ONLY = 'reference_only';
    public const VERDICT_ALLOWED = 'allowed';
    public const VERDICT_UNKNOWN = 'unknown';

    /**
     * Hard-forbidden tokens. Their presence is always a policy violation in any
     * Atlas workspace, CI, governed measurement or canonical experiment.
     *
     * @var array<string,string>
     */
    private const FORBIDDEN = [
        '--dangerously-skip-permissions' => 'dangerous_skip_permissions_forbidden',
    ];

    /**
     * Tokens that widen scope / capability and may only proceed behind explicit
     * review or a Work-Packet update — never silently inside a runtime flow.
     *
     * @var array<string,string>
     */
    private const REVIEW_REQUIRED = [
        '--add-dir' => 'add_dir_expands_scope_requires_work_packet_update',
        'plugin' => 'plugin_requires_review_and_policy',
        'plugins' => 'plugin_requires_review_and_policy',
        '/mcp' => 'mcp_capability_requires_review',
        '/skills' => 'skills_capability_requires_review',
    ];

    /**
     * Tokens that exist as ecosystem capability evidence but must NOT become an
     * Atlas driver / hot-path. They are discovery references only.
     *
     * @var array<string,string>
     */
    private const REFERENCE_ONLY = [
        '--print' => 'driver_flag_reference_only_not_hot_path',
        '--prompt' => 'driver_flag_reference_only_not_hot_path',
        '-p' => 'driver_flag_reference_only_not_hot_path',
        '--prompt-interactive' => 'interactive_flag_outside_hot_path',
        '-i' => 'interactive_flag_outside_hot_path',
        '--continue' => 'continue_not_for_atlas_work_packet',
        '-c' => 'continue_not_for_atlas_work_packet',
        '--conversation' => 'manual_external_audit_not_atlas_memory',
        '--print-timeout' => 'wrapper_flag_not_canonical_route',
        '/model' => 'model_observed_not_routing_authority',
        '/agents' => 'subagents_monitored_not_agent_control_plane',
        '/tasks' => 'subagents_monitored_not_agent_control_plane',
        'install' => 'human_operated_never_during_driver_runtime',
        'update' => 'human_operated_never_during_driver_runtime',
        'changelog' => 'human_operated_never_during_driver_runtime',
    ];

    /**
     * Tokens explicitly permitted as the safe baseline / for human audit.
     *
     * @var array<string,string>
     */
    private const ALLOWED = [
        '--sandbox' => 'sandbox_is_recommended_default_for_atlas_workspace',
        '--log-file' => 'allowed_if_logs_redacted_before_evidence_or_ui',
        '/permissions' => 'permissions_kept_restrictive',
        '/resume' => 'permitted_for_human_audit',
        '/rewind' => 'permitted_for_human_audit',
        '/logout' => 'permitted_to_end_session_credentials',
    ];

    /**
     * Classify one `agy` flag / subcommand / slash-command token.
     *
     * @return array<string,mixed>
     */
    public function classifyToken(string $token): array
    {
        $normalized = $this->normalizeToken($token);

        [$verdict, $reason] = $this->lookupToken($normalized);

        return [
            'schema' => self::REFERENCE_SCHEMA,
            'token' => $normalized,
            'verdict' => $verdict,
            'reason' => $reason,
            // A classification never authorizes a CLI runtime by itself; the
            // sovereign route stays SDK-first.
            'implementation_allowed' => false,
        ];
    }

    /**
     * Decide whether a proposed `agy` invocation is permitted as governed
     * discovery, or is a sovereignty violation that must be blocked.
     *
     * @param array<string,mixed> $request
     *        tokens        : list<string>  flags/subcommands/slash-commands present (default [])
     *        atlas_workspace : bool  the invocation targets an Atlas/canonical workspace (default true)
     *        as_runtime_driver : bool  caller intends to use `agy` as an Atlas runtime driver (default false)
     *        sdk_path_active : bool  the SDK-first programmable route is still available (default true)
     *        work_packet_id  : string|null  scope lock authorizing the experiment (default null)
     *        secrets_in_prompt : bool  canonical secrets/tokens placed in the CLI prompt (default false)
     *
     * @return array<string,mixed>
     */
    public function decideInvocation(array $request): array
    {
        $tokens = $this->normalizeTokens($request['tokens'] ?? []);
        $atlasWorkspace = (bool) ($request['atlas_workspace'] ?? true);
        $asDriver = (bool) ($request['as_runtime_driver'] ?? false);
        $sdkActive = (bool) ($request['sdk_path_active'] ?? true);
        $workPacket = $this->normalizeWorkPacket($request['work_packet_id'] ?? null);
        $secretsInPrompt = (bool) ($request['secrets_in_prompt'] ?? false);

        $tokenVerdicts = array_map(fn (string $t): array => $this->classifyToken($t), $tokens);

        $forbiddenHits = array_values(array_filter(
            $tokenVerdicts,
            fn (array $v): bool => $v['verdict'] === self::VERDICT_FORBIDDEN,
        ));
        $reviewHits = array_values(array_filter(
            $tokenVerdicts,
            fn (array $v): bool => $v['verdict'] === self::VERDICT_REVIEW_REQUIRED,
        ));

        $hasSandbox = $this->containsNormalized($tokens, '--sandbox');

        $blockers = [];

        // Rule 1 — secrets in the prompt are an unconditional hard stop.
        // "Nunca envie ... secrets ... para o CLI."
        if ($secretsInPrompt) {
            $blockers[] = 'secrets_or_canonical_memory_sent_to_cli';
        }

        // Rule 2 — any hard-forbidden flag (e.g. dangerous skip permissions)
        // blocks unconditionally.
        foreach ($forbiddenHits as $hit) {
            $blockers[] = $hit['reason'];
        }

        // Rule 3 — wrapper/driver use of the CLI is forbidden while the SDK-first
        // route is active. "Proibido criar wrapper/driver CLI enquanto a rota
        // SDK-first estiver ativa."
        if ($asDriver && $sdkActive) {
            $blockers[] = 'cli_runtime_driver_forbidden_while_sdk_first_active';
        }

        // Rule 4 — scope-expanding / review-required tokens must not ride inside
        // a driver flow, and scope cannot widen without a Work Packet.
        foreach ($reviewHits as $hit) {
            if ($asDriver) {
                $blockers[] = $hit['reason'].'_blocked_in_driver_flow';
            } elseif ($hit['token'] === '--add-dir' && $workPacket === null) {
                $blockers[] = 'add_dir_without_work_packet_scope_lock';
            }
        }

        $permitted = $blockers === [];

        // Sandbox is the default baseline; a permitted Atlas-workspace discovery
        // run that omits --sandbox is flagged as a risk event (not blocked).
        $warnings = [];
        if ($permitted && $atlasWorkspace && ! $hasSandbox) {
            $warnings[] = 'sandbox_default_missing_treat_as_risk_event';
        }

        return [
            'schema' => self::REFERENCE_SCHEMA,
            'permitted' => $permitted,
            'mode' => $permitted ? 'governed_discovery_reference' : 'blocked',
            'as_runtime_driver' => $asDriver,
            'sdk_path_active' => $sdkActive,
            'atlas_workspace' => $atlasWorkspace,
            'sandbox_default_applied' => $hasSandbox,
            'work_packet_present' => $workPacket !== null,
            'forbidden_tokens' => array_values(array_map(fn (array $v): string => $v['token'], $forbiddenHits)),
            'review_required_tokens' => array_values(array_map(fn (array $v): string => $v['token'], $reviewHits)),
            'blockers' => $blockers,
            'warnings' => $warnings,
            // Sovereign authority never transfers to the CLI.
            'routing_authority' => 'atlas_decide',
            'memory_authority' => 'atlas_memory',
            'implementation_allowed' => false,
        ];
    }

    /**
     * Map a model seen in the CLI selector to its governed classification.
     * "Se o CLI mostrar Gemini, Claude ou GPT-OSS, registre como
     * `model_observed`, nao como policy Atlas."
     *
     * @return array<string,mixed>
     */
    public function classifyObservedModel(?string $model): array
    {
        $normalized = is_string($model) ? trim($model) : '';

        return [
            'schema' => self::REFERENCE_SCHEMA,
            'model_observed' => $normalized !== '' ? $normalized : null,
            // Observation only — routing authority stays with Atlas Decide.
            'is_atlas_routing_policy' => false,
            'routing_authority' => 'atlas_decide',
            'classification' => 'model_observed',
        ];
    }

    /**
     * Build the minimal `atlas.antigravity_cli_reference.v1` envelope from an
     * observed local snapshot. All required fields are present and
     * `implementation_allowed` is hard-pinned to false.
     *
     * @param array<string,mixed> $snapshot
     *        binary_path     : string|null
     *        present         : bool
     *        help_hash       : string|null
     *        flags_seen      : list<string>
     *        models_observed : list<string>
     *
     * @return array<string,mixed>
     */
    public function referenceEnvelope(array $snapshot = []): array
    {
        $flagsSeen = AtlasAaeosStringListNormalizer::lowerTrimmedStrings($snapshot['flags_seen'] ?? []);
        $modelsObserved = AtlasAaeosStringListNormalizer::lowerTrimmedStrings($snapshot['models_observed'] ?? []);

        $sandboxSupported = $this->containsNormalized($flagsSeen, '--sandbox');

        $dangerousFlags = array_values(array_filter(
            $flagsSeen,
            fn (string $f): bool => array_key_exists($f, self::FORBIDDEN),
        ));

        return [
            'schema' => self::REFERENCE_SCHEMA,
            'binary_path' => $this->normalizeNullableString($snapshot['binary_path'] ?? null),
            'present' => (bool) ($snapshot['present'] ?? false),
            'help_hash' => $this->normalizeNullableString($snapshot['help_hash'] ?? null),
            'flags_seen' => $flagsSeen,
            'sandbox_supported' => $sandboxSupported,
            'dangerous_flags' => $dangerousFlags,
            'models_observed' => $modelsObserved,
            // Hard invariant: a new field can never open a CLI runtime.
            'implementation_allowed' => false,
        ];
    }

    /**
     * The canonical reference-only flow steps (observe -> record risk ->
     * implement via SDK). The forbidden driver flow is never returned here.
     *
     * @return list<string>
     */
    public function referenceFlow(): array
    {
        return [
            'observe_cli_docs_flags_models_permissions',
            'record_risks_and_capabilities_affecting_sdk_first_strategy',
            'implement_and_measure_via_governed_sdk_not_agy',
        ];
    }

    /**
     * Canonical contract manifest: state, sovereign invariants and the closed
     * token classification tables.
     *
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return [
            'schema_version' => self::REFERENCE_SCHEMA,
            'implementation_state' => 'reference_only_sdk_first_no_cli_driver',
            'implementation_allowed' => false,
            'forbidden_tokens' => array_keys(self::FORBIDDEN),
            'review_required_tokens' => array_keys(self::REVIEW_REQUIRED),
            'reference_only_tokens' => array_keys(self::REFERENCE_ONLY),
            'allowed_tokens' => array_keys(self::ALLOWED),
            'invariants' => [
                'dangerous_skip_permissions_forbidden',
                'sandbox_default_for_atlas_workspace',
                'atlas_decide_routing_authority_preserved',
                'atlas_memory_authority_preserved',
                'no_cli_runtime_driver_while_sdk_first_active',
                'add_dir_cannot_bypass_allowed_files',
                'plugins_mcp_require_review_before_workspace',
                'secrets_never_sent_to_cli',
            ],
            'reference_flow' => $this->referenceFlow(),
        ];
    }

    /**
     * @param list<string> $tokens
     */
    private function containsNormalized(array $tokens, string $needle): bool
    {
        return in_array($this->normalizeToken($needle), $tokens, true);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function lookupToken(string $normalized): array
    {
        if (array_key_exists($normalized, self::FORBIDDEN)) {
            return [self::VERDICT_FORBIDDEN, self::FORBIDDEN[$normalized]];
        }
        if (array_key_exists($normalized, self::REVIEW_REQUIRED)) {
            return [self::VERDICT_REVIEW_REQUIRED, self::REVIEW_REQUIRED[$normalized]];
        }
        if (array_key_exists($normalized, self::REFERENCE_ONLY)) {
            return [self::VERDICT_REFERENCE_ONLY, self::REFERENCE_ONLY[$normalized]];
        }
        if (array_key_exists($normalized, self::ALLOWED)) {
            return [self::VERDICT_ALLOWED, self::ALLOWED[$normalized]];
        }

        return [self::VERDICT_UNKNOWN, 'token_not_in_reference_tables'];
    }

    /**
     * @param mixed $tokens
     * @return list<string>
     */
    private function normalizeTokens(mixed $tokens): array
    {
        return AtlasAaeosStringListNormalizer::lowerTrimmedStrings($tokens);
    }

    private function normalizeToken(string $token): string
    {
        return strtolower(trim($token));
    }

    private function normalizeWorkPacket(mixed $id): ?string
    {
        if (is_string($id) && trim($id) !== '') {
            return trim($id);
        }

        return null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }
}

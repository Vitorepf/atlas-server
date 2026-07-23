<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Atlas AI Runtime Language Boundaries decider.
 *
 * Pure, deterministic runtime for the canonical contract that separates the
 * role of Laravel, Python, Go and Swift in Atlas AI. The doc states a single
 * Regra Mae — "Laravel decide e governa; Python, Go e Swift executam
 * capacidades especializadas" — and three executable obligations that this
 * service enforces without softening:
 *
 *  1. Matriz De Decisao (doc "Matriz De Decisao"): every "need" has exactly one
 *     owning language with a stated reason. {@see routeNeed()} resolves a need
 *     to its owner. Kernel/governance concerns (api, auth, policy, receipt,
 *     ledger, gates, domain orchestration, provider routing) ALWAYS resolve to
 *     Laravel — they are sovereignty and can never be delegated.
 *
 *  2. Boundary verdict (doc "Papel Do Python/Go/Swift" — the explicit
 *     "Nao use X para..." lists — plus "Anti-Duplicacao"): given a runtime and
 *     a proposed action, {@see evaluateAction()} returns allow|block. Any action
 *     that decides domain/model/provider/policy/repair/flow, writes
 *     memory/evidence/policy directly, creates a parallel queue/scheduler, runs a
 *     destructive action without receipt+approval, or copies a Kernel capability
 *     is BLOCKED for every external runtime. A runtime asking for a capability the
 *     Kernel already owns must call the Kernel / receive a capability token, never
 *     copy it.
 *
 *  3. Communication contract (doc "Contrato De Comunicacao"): every external
 *     runtime must receive a Kernel-signed payload (schema
 *     `atlas.runtime.invoke.v1`) and answer with `atlas.runtime.result.v1`.
 *     {@see validateInvoke()} and {@see validateResult()} pin the required fields,
 *     the allowed runtime ids and the allowed result statuses. A missing
 *     decision_receipt_hash means the Kernel did not sign it -> invalid.
 *
 * The doc separates governance (this PHP-side decider, which decides) from the
 * static-scan compliance reporter that already lives at
 * App\Services\Ai\Kernel\Architecture\AtlasRuntimeLanguageBoundaryReportService
 * (which detects forbidden imports in the wrong scope). This service is the
 * decision seam, not the scanner: it consumes already-normalized inputs and
 * emits a verdict. It never runs a process, reads a doc, or touches a database.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
 */
final class AtlasAiRuntimeLanguageBoundariesService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.runtime_language_boundaries.v1';

    /** Kernel-signed request envelope schema (doc "Contrato De Comunicacao"). */
    public const INVOKE_SCHEMA = 'atlas.runtime.invoke.v1';

    /** Runtime response envelope schema (doc "Contrato De Comunicacao"). */
    public const RESULT_SCHEMA = 'atlas.runtime.result.v1';

    /** The Kernel/Maestro. It decides and governs; it is never an external runtime. */
    public const KERNEL = 'laravel';

    /**
     * Canonical external runtime ids (doc "runtime": "python_ai_data|go_edge|swift_native_mac"
     * and "Identidade canonica do runtime: swift_native_mac").
     *
     * @var list<string>
     */
    public const EXTERNAL_RUNTIMES = [
        'python_ai_data',
        'go_edge',
        'swift_native_mac',
    ];

    /**
     * Allowed result statuses (doc "status": "succeeded|failed|blocked|needs_review").
     *
     * @var list<string>
     */
    public const RESULT_STATUSES = [
        'succeeded',
        'failed',
        'blocked',
        'needs_review',
    ];

    /**
     * Required fields of a Kernel-signed invoke payload (doc "Contrato De
     * Comunicacao" request JSON).
     *
     * @var list<string>
     */
    public const INVOKE_REQUIRED = [
        'schema_version',
        'envelope_id',
        'decision_receipt_hash',
        'runtime',
        'domain_id',
        'flow_id',
        'input',
        'policy',
        'limits',
        'evidence_contract',
    ];

    /**
     * Required fields of a runtime result payload (doc "Contrato De Comunicacao"
     * response JSON).
     *
     * @var list<string>
     */
    public const RESULT_REQUIRED = [
        'schema_version',
        'status',
        'artifacts',
        'metrics',
        'findings',
        'evidence',
    ];

    /**
     * The Decision Matrix, encoded need -> [owner, reason]. Keys are the stable
     * need ids; the doc's "Matriz De Decisao" rows map 1:1 here.
     *
     * @var array<string,array{owner:string,reason:string}>
     */
    public const DECISION_MATRIX = [
        // Kernel-owned: sovereignty, never delegated.
        'api_auth_policy_receipt_ledger_gates' => ['owner' => self::KERNEL, 'reason' => 'kernel_e_governanca'],
        'domain_orchestration' => ['owner' => self::KERNEL, 'reason' => 'contrato_central_e_auditavel'],
        'provider_routing' => ['owner' => self::KERNEL, 'reason' => 'atlas_decide_e_provider_driver'],

        // Python AI/Data runtime.
        'graph_rag_vector_rag_embeddings' => ['owner' => 'python_ai_data', 'reason' => 'ecossistema_de_ia_dados'],
        'pandas_polars_analytics' => ['owner' => 'python_ai_data', 'reason' => 'data_crunching_eficiente'],
        'ml_local_leve' => ['owner' => 'python_ai_data', 'reason' => 'scikit_learn_e_modelos_locais'],
        'swarm_agentic_experiment' => ['owner' => 'python_ai_data', 'reason' => 'frameworks_de_pesquisa'],

        // Go edge/concurrency runtime.
        'webhook_click_postback_em_massa' => ['owner' => 'go_edge', 'reason' => 'concorrencia_e_baixa_latencia'],
        'daemon_local_leve' => ['owner' => 'go_edge', 'reason' => 'binario_pequeno_e_estavel'],
        'streaming_backpressure' => ['owner' => 'go_edge', 'reason' => 'rede_e_io_concorrente'],

        // Swift native Mac runtime.
        'touch_id_keychain_notificacoes' => ['owner' => 'swift_native_mac', 'reason' => 'apis_apple_nativas'],
        'fsevents_accessibility_screencapture' => ['owner' => 'swift_native_mac', 'reason' => 'contexto_local_opt_in'],
        'wake_word_vad_echo_cancel_mic' => ['owner' => 'swift_native_mac', 'reason' => 'latencia_sub_50ms_zero_stream_antes_de_wake'],
        'livekit_client_sdk_edge_mac' => ['owner' => 'swift_native_mac', 'reason' => 'qualidade_nativa_audio_webrtc'],
    ];

    /**
     * Authorities that NO external runtime may exercise — the union of the doc's
     * "Nao use ... para" lists across Python/Go/Swift plus "Anti-Duplicacao".
     * Each maps to the reason it is forbidden. Used by {@see evaluateAction()}.
     *
     * @var array<string,string>
     */
    public const FORBIDDEN_AUTHORITIES = [
        'decide_provider_or_model' => 'decidir provider/modelo e Atlas Decide do Kernel',
        'decide_domain_or_flow' => 'classificar domain/flow e do Kernel',
        'decide_policy_or_repair' => 'policy e repair limits sao do Kernel',
        'decide_autonomy_or_gate' => 'autonomia e gates sao do Kernel',
        'write_memory_directly' => 'gravar memoria sem privacy/gate e proibido',
        'write_policy_or_context' => 'escrever Policy/Context pelo runtime e proibido',
        'own_evidence_source_of_truth' => 'runtime nao pode ser source of truth de Evidence',
        'create_parallel_queue_or_scheduler' => 'criar fila/scheduler paralelo ao canonico e proibido',
        'replace_kernel_api' => 'substituir a Laravel API e proibido',
        'destructive_without_receipt_approval' => 'acao destrutiva exige receipt e approval',
        'copy_kernel_capability' => 'capability do Kernel deve ser chamada, nunca copiada (Anti-Duplicacao)',
    ];

    /**
     * The Anti-Duplicacao list (doc "Anti-Duplicacao", 9 items): capabilities a
     * runtime must call the Kernel for, never reimplement.
     *
     * @var list<string>
     */
    public const NON_DUPLICABLE_CAPABILITIES = [
        'policy_engine',
        'provider_selection',
        'memory_privacy',
        'repair_limits',
        'domain_registry',
        'evidence_schema',
        'approval_flow',
        'canonical_scheduler',
        'embedding_service_beyond_fallback_hash_adapter',
    ];

    /**
     * Route a "need" to its single owning language with the documented reason.
     *
     * An unknown need is NEVER guessed to a runtime: it falls back to the Kernel
     * with a `needs_placement` flag, because the doc requires place-feature before
     * any new runtime work — an unclassified need cannot be handed to Python/Go/Swift.
     *
     * @return array{
     *   schema_version: string,
     *   need: string,
     *   owner: string,
     *   reason: string,
     *   is_kernel: bool,
     *   known_need: bool,
     *   needs_placement: bool,
     * }
     */
    public function routeNeed(string $need): array
    {
        $key = $this->normalize($need);
        $known = array_key_exists($key, self::DECISION_MATRIX);

        if (! $known) {
            return [
                'schema_version' => self::RECEIPT_SCHEMA,
                'need' => $key,
                'owner' => self::KERNEL,
                'reason' => 'need_nao_classificado_roteia_para_kernel_ate_place_feature',
                'is_kernel' => true,
                'known_need' => false,
                'needs_placement' => true,
            ];
        }

        $row = self::DECISION_MATRIX[$key];

        return [
            'schema_version' => self::RECEIPT_SCHEMA,
            'need' => $key,
            'owner' => $row['owner'],
            'reason' => $row['reason'],
            'is_kernel' => $row['owner'] === self::KERNEL,
            'known_need' => true,
            'needs_placement' => false,
        ];
    }

    /**
     * Decide whether a proposed action by an external runtime is allowed.
     *
     * The action is identified by its `authority` id (e.g. "graph_rag",
     * "decide_provider_or_model"). The verdict is BLOCK if:
     *   - the runtime id is not a known external runtime; or
     *   - the authority is in FORBIDDEN_AUTHORITIES (no runtime may decide
     *     governance, write canonical state, or run a parallel scheduler); or
     *   - the action duplicates a Kernel capability instead of calling it.
     *
     * Otherwise the action is allowed only when it falls inside the runtime's own
     * specialised scope (the doc's "Use X quando..." lists), encoded as
     * allowed authorities per runtime.
     *
     * @return array{
     *   schema_version: string,
     *   runtime: string,
     *   authority: string,
     *   verdict: string,
     *   allowed: bool,
     *   reasons: list<string>,
     *   must_call_kernel: bool,
     * }
     */
    public function evaluateAction(string $runtime, string $authority): array
    {
        $rt = $this->normalize($runtime);
        $auth = $this->normalize($authority);
        $reasons = [];
        $mustCallKernel = false;

        if (! in_array($rt, self::EXTERNAL_RUNTIMES, true)) {
            return $this->actionVerdict($rt, $auth, false, ['unknown_external_runtime'], false);
        }

        if (array_key_exists($auth, self::FORBIDDEN_AUTHORITIES)) {
            $reasons[] = 'forbidden_authority:'.$auth;
            $reasons[] = self::FORBIDDEN_AUTHORITIES[$auth];
            $mustCallKernel = true;

            return $this->actionVerdict($rt, $auth, false, $reasons, $mustCallKernel);
        }

        if (in_array($auth, self::NON_DUPLICABLE_CAPABILITIES, true)) {
            $reasons[] = 'anti_duplication:'.$auth;
            $reasons[] = 'must_call_kernel_or_receive_capability_token';

            return $this->actionVerdict($rt, $auth, false, $reasons, true);
        }

        $allowedForRuntime = $this->allowedAuthorities($rt);
        if (in_array($auth, $allowedForRuntime, true)) {
            return $this->actionVerdict($rt, $auth, true, ['within_runtime_specialised_scope'], false);
        }

        // Not forbidden, not duplicating, but also not in this runtime's stated
        // scope: the doc keeps each runtime narrow, so an out-of-scope capability
        // is blocked and must be routed (place-feature) rather than assumed.
        $reasons[] = 'out_of_runtime_scope:'.$rt;
        $reasons[] = 'route_via_place_feature_before_assuming_runtime';

        return $this->actionVerdict($rt, $auth, false, $reasons, false);
    }

    /**
     * Validate a Kernel-signed invoke payload against `atlas.runtime.invoke.v1`.
     *
     * @param  array<string,mixed>  $payload
     * @return array{
     *   schema_version: string,
     *   valid: bool,
     *   missing: list<string>,
     *   reasons: list<string>,
     *   kernel_signed: bool,
     * }
     */
    public function validateInvoke(array $payload): array
    {
        $missing = $this->missingFields($payload, self::INVOKE_REQUIRED);
        $reasons = [];

        foreach ($missing as $field) {
            $reasons[] = 'missing_required_field:'.$field;
        }

        // The receipt hash is what proves the Kernel signed it: empty == unsigned.
        $kernelSigned = ! in_array('decision_receipt_hash', $missing, true)
            && AtlasAaeosValueNormalizer::isNonBlankString($payload['decision_receipt_hash'] ?? null);
        if (! $kernelSigned) {
            $reasons[] = 'kernel_signature_missing:decision_receipt_hash';
        }

        $schemaOk = ($payload['schema_version'] ?? null) === self::INVOKE_SCHEMA;
        if (! in_array('schema_version', $missing, true) && ! $schemaOk) {
            $reasons[] = 'wrong_schema_version_expected:'.self::INVOKE_SCHEMA;
        }

        if (! in_array('runtime', $missing, true)
            && ! in_array($this->normalize((string) ($payload['runtime'] ?? '')), self::EXTERNAL_RUNTIMES, true)) {
            $reasons[] = 'unknown_target_runtime';
        }

        $valid = $missing === [] && $schemaOk && $kernelSigned
            && in_array($this->normalize((string) ($payload['runtime'] ?? '')), self::EXTERNAL_RUNTIMES, true);

        return [
            'schema_version' => self::RECEIPT_SCHEMA,
            'valid' => $valid,
            'missing' => $missing,
            'reasons' => $reasons,
            'kernel_signed' => $kernelSigned,
        ];
    }

    /**
     * Validate a runtime result payload against `atlas.runtime.result.v1`.
     *
     * @param  array<string,mixed>  $payload
     * @return array{
     *   schema_version: string,
     *   valid: bool,
     *   missing: list<string>,
     *   reasons: list<string>,
     *   status: string,
     *   status_known: bool,
     * }
     */
    public function validateResult(array $payload): array
    {
        $missing = $this->missingFields($payload, self::RESULT_REQUIRED);
        $reasons = [];

        foreach ($missing as $field) {
            $reasons[] = 'missing_required_field:'.$field;
        }

        $status = $this->normalize((string) ($payload['status'] ?? ''));
        $statusKnown = in_array($status, self::RESULT_STATUSES, true);
        if (! in_array('status', $missing, true) && ! $statusKnown) {
            $reasons[] = 'unknown_result_status:'.$status;
        }

        $schemaOk = ($payload['schema_version'] ?? null) === self::RESULT_SCHEMA;
        if (! in_array('schema_version', $missing, true) && ! $schemaOk) {
            $reasons[] = 'wrong_schema_version_expected:'.self::RESULT_SCHEMA;
        }

        $valid = $missing === [] && $schemaOk && $statusKnown;

        return [
            'schema_version' => self::RECEIPT_SCHEMA,
            'valid' => $valid,
            'missing' => $missing,
            'reasons' => $reasons,
            'status' => $status,
            'status_known' => $statusKnown,
        ];
    }

    /**
     * The full canonical decision surface, for the CLI and audits.
     *
     * @return array{
     *   schema_version: string,
     *   regra_mae: string,
     *   kernel: string,
     *   external_runtimes: list<string>,
     *   decision_matrix: array<string,array{owner:string,reason:string}>,
     *   forbidden_authorities: list<string>,
     *   non_duplicable_capabilities: list<string>,
     *   invoke_required: list<string>,
     *   result_required: list<string>,
     *   result_statuses: list<string>,
     * }
     */
    public function contract(): array
    {
        return [
            'schema_version' => self::RECEIPT_SCHEMA,
            'regra_mae' => 'laravel_decide_e_governa_python_go_swift_executam_especializado',
            'kernel' => self::KERNEL,
            'external_runtimes' => self::EXTERNAL_RUNTIMES,
            'decision_matrix' => self::DECISION_MATRIX,
            'forbidden_authorities' => array_keys(self::FORBIDDEN_AUTHORITIES),
            'non_duplicable_capabilities' => self::NON_DUPLICABLE_CAPABILITIES,
            'invoke_required' => self::INVOKE_REQUIRED,
            'result_required' => self::RESULT_REQUIRED,
            'result_statuses' => self::RESULT_STATUSES,
        ];
    }

    /**
     * The specialised authorities each external runtime owns (doc "Use X quando...").
     *
     * @return list<string>
     */
    private function allowedAuthorities(string $runtime): array
    {
        return match ($runtime) {
            'python_ai_data' => [
                'graph_rag', 'vector_rag', 'embeddings', 'rerank', 'clustering',
                'pandas_polars_analytics', 'ml_local_leve', 'anomaly_detection',
                'predictive_scoring', 'multimodal_processing', 'swarm_agentic_experiment',
            ],
            'go_edge' => [
                'webhook_click_postback', 'high_volume_ingestion', 'long_connections',
                'watchers', 'bridges', 'streaming', 'backpressure', 'proxy_gateway',
                'callback_receiver', 'event_forwarder', 'daemon_local_leve',
                'telemetry_collector', 'health_probe', 'log_shipper',
            ],
            'swift_native_mac' => [
                'keychain', 'touch_id', 'xpc_helper', 'native_notifications', 'menu_bar',
                'fsevents', 'nsworkspace_focus', 'accessibility_opt_in', 'screencapturekit_manual',
                'core_spotlight', 'shortcuts_app_intents', 'core_ml_leve',
                'wake_word_local', 'vad', 'echo_cancel', 'mic_capture', 'livekit_client',
            ],
            default => [],
        };
    }

    /**
     * @param  list<string>  $reasons
     * @return array{
     *   schema_version: string,
     *   runtime: string,
     *   authority: string,
     *   verdict: string,
     *   allowed: bool,
     *   reasons: list<string>,
     *   must_call_kernel: bool,
     * }
     */
    private function actionVerdict(string $runtime, string $authority, bool $allowed, array $reasons, bool $mustCallKernel): array
    {
        return [
            'schema_version' => self::RECEIPT_SCHEMA,
            'runtime' => $runtime,
            'authority' => $authority,
            'verdict' => $allowed ? 'allow' : 'block',
            'allowed' => $allowed,
            'reasons' => array_values($reasons),
            'must_call_kernel' => $mustCallKernel,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $required
     * @return list<string>
     */
    private function missingFields(array $payload, array $required): array
    {
        $missing = [];
        foreach ($required as $field) {
            if (! array_key_exists($field, $payload)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
